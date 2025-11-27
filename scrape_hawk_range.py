#!/usr/bin/env python3
"""Scrape hawk.live match data across a date range and export CSV."""
import csv
import datetime as dt
import html
import json
import random
import socket
import ssl
import sys
import time
import unicodedata
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
import re

try:
    import certifi
except ImportError:
    certifi = None

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Safari/537.36",
}

EXCLUDE_KEYWORDS = [
    "ultras",
    "destiny",
    "mad",
    "dota 2",
    "ancients",
    "oq",
    "1xbet",
    "appl",
    "impacto",
    "evella",
    "xmas",
    "tdl",
    "radiant",
    "fastinvitational",
    "iesf",
    "dos",
]

# Base delays (seconds) between requests; jitter is added to each call
PAGE_DELAY = 0.4
MATCH_DELAY = 0.3
PAGE_JITTER = 0.2
MATCH_JITTER = 0.15

MAX_RETRIES = 4

OUTPUT_HEADER = [
    "date",
    "championship",
    "series_id",
    "map_number",
    "hawk_match_id",
    "team1",
    "team2",
    "team1_heroes",
    "team2_heroes",
    "winner",
    "delta",
    "delta_favored_team",
    "team1_odds",
    "team2_odds",
    "game_time_seconds",
    "game_time_minutes",
]


def normalize(name: str) -> str:
    return re.sub(r"[^a-z0-9]", "", unicodedata.normalize("NFKD", name).lower())


def load_hero_data(cs_path: Path) -> Dict[str, object]:
    text = cs_path.read_text(encoding="utf-8").strip()
    if not text:
        raise ValueError("cs.json is empty")
    payload = None
    if text.startswith("{"):
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            payload = None
    if payload is not None:
        heroes = payload.get("heroes") or []
        heroes_wr_raw = payload.get("heroes_wr") or []
        win_rates = payload.get("win_rates") or []
    else:
        heroes = json.loads(re.search(r"var heroes = (\[.*?\]), heroes_bg", text, re.S).group(1))
        heroes_wr_raw = json.loads(re.search(r"heroes_wr = (\[.*?\]), win_rates", text, re.S).group(1))
        win_rates = json.loads(re.search(r"win_rates = (\[.*?\]), update_time", text, re.S).group(1))
    if not heroes or not heroes_wr_raw or not win_rates:
        raise ValueError("cs.json is missing hero win rate payloads")
    heroes_wr = [float(x) if x is not None else 50.0 for x in heroes_wr_raw]
    hero_index = {normalize(name): idx for idx, name in enumerate(heroes)}
    return {
        "heroes_wr": heroes_wr,
        "win_rates": win_rates,
        "hero_index": hero_index,
    }


_SSL_CONTEXT = None
if certifi:
    _SSL_CONTEXT = ssl.create_default_context(cafile=certifi.where())


def fetch_url(url: str, delay: float, jitter: float) -> str:
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            sleep_for = delay + random.uniform(0, jitter)
            time.sleep(sleep_for)
            req = Request(url, headers=HEADERS)
            if _SSL_CONTEXT is not None:
                resp = urlopen(req, timeout=25, context=_SSL_CONTEXT)
            else:
                resp = urlopen(req, timeout=25)
            with resp:
                body = resp.read().decode("utf-8", errors="replace")
            return body
        except HTTPError as exc:
            if exc.code in (429, 500, 502, 503, 504) and attempt < MAX_RETRIES:
                backoff = delay * (attempt + 1) + random.uniform(0, jitter * (attempt + 1))
                time.sleep(backoff)
                continue
            raise
        except (URLError, TimeoutError, socket.timeout):
            if attempt < MAX_RETRIES:
                backoff = delay * (attempt + 1) + random.uniform(0, jitter * (attempt + 1))
                time.sleep(backoff)
                continue
            raise
    raise RuntimeError(f"Failed to fetch {url} after {MAX_RETRIES} attempts")


def compute_delta(hero_data: Dict[str, object], team1_heroes: List[str], team2_heroes: List[str]) -> Optional[float]:
    heroes_wr = hero_data["heroes_wr"]
    win_rates = hero_data["win_rates"]
    hero_index = hero_data["hero_index"]
    try:
        t1_ids = [hero_index[normalize(h)] for h in team1_heroes]
        t2_ids = [hero_index[normalize(h)] for h in team2_heroes]
    except KeyError:
        return None
    base1 = sum(heroes_wr[i] for i in t1_ids)
    base2 = sum(heroes_wr[i] for i in t2_ids)
    adv1 = 0.0
    for hid1 in t1_ids:
        for hid2 in t2_ids:
            entry = win_rates[hid2][hid1]
            if entry is not None:
                adv1 += float(entry[0])
    adv2 = 0.0
    for hid1 in t2_ids:
        for hid2 in t1_ids:
            entry = win_rates[hid2][hid1]
            if entry is not None:
                adv2 += float(entry[0])
    return (base1 + adv1) - (base2 + adv2)


def extract_odds(match_props: Dict[str, object]) -> (Optional[str], Optional[str], Optional[str], Optional[str]):
    odds_array = match_props.get("match_odds_info_array") or []
    earliest = None
    for provider in odds_array:
        is_team1_first = provider.get("is_team1_first", True)
        for odds in provider.get("odds") or []:
            first = odds.get("first_team_winner")
            second = odds.get("second_team_winner")
            if first is None or second is None:
                continue
            created = odds.get("created_at")
            entry = {
                "team1_odds": first,
                "team2_odds": second,
                "created_at": created,
                "provider": provider.get("odds_provider_code_name"),
                "is_team1_first": is_team1_first,
            }
            if earliest is None or (created and created < earliest.get("created_at", "")):
                earliest = entry
    if not earliest:
        return None, None, None, None
    team1_odds = earliest["team1_odds"]
    team2_odds = earliest["team2_odds"]
    if earliest.get("is_team1_first") is False:
        team1_odds, team2_odds = team2_odds, team1_odds
    return team1_odds, team2_odds, earliest.get("provider"), earliest.get("created_at")


def extract_final_game_time(match_props: Dict[str, object]) -> Tuple[Optional[int], Optional[str]]:
    """
    Convert the recorded game_time seconds into a clock-style minutes string.

    Hawk exposes chronological state snapshots that include `game_time` measured in
    seconds. Some responses may append additional states even after the match
    finishes, so we consider the maximum value instead of the final list entry.
    """
    states = (match_props.get("init_match") or {}).get("states") or []
    if not states:
        return None, None
    max_seconds: Optional[int] = None
    for state in states:
        state = state or {}
        raw_time = state.get("game_time")
        if raw_time is None:
            continue
        try:
            seconds = int(raw_time)
        except (TypeError, ValueError):
            continue
        if max_seconds is None or seconds > max_seconds:
            max_seconds = seconds
    if max_seconds is None:
        return None, None
    minutes, seconds_remainder = divmod(max_seconds, 60)
    clock_minutes = f"{minutes}:{seconds_remainder:02d}"
    return max_seconds, clock_minutes


def parse_match_page(match_id: int) -> Dict[str, object]:
    url = f"https://hawk.live/matches/{match_id}"
    text = fetch_url(url, MATCH_DELAY, MATCH_JITTER)
    json_blob = json.loads(html.unescape(re.search(r'data-page="([^"]+)"', text).group(1)))
    return json_blob["props"]


def should_exclude(championship_name: str) -> bool:
    name_lower = championship_name.lower()
    return any(keyword in name_lower for keyword in EXCLUDE_KEYWORDS)


def iter_dates(start: dt.date, end: dt.date):
    current = start
    while current <= end:
        yield current
        current += dt.timedelta(days=1)


def scrape_range(start_date: dt.date, end_date: dt.date, output_path: Path):
    hero_data = load_hero_data(Path("cs.json"))
    total_rows = 0
    skipped = 0
    excluded_championships = 0
    write_header = not output_path.exists() or output_path.stat().st_size == 0
    with output_path.open("a", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        if write_header:
            writer.writerow(OUTPUT_HEADER)
        total_days = (end_date - start_date).days + 1
        processed_days = 0
        start_time = time.time()
        for day in iter_dates(start_date, end_date):
            page_url = f"https://hawk.live/matches/recent/{day.isoformat()}"
            try:
                page_text = fetch_url(page_url, PAGE_DELAY, PAGE_JITTER)
            except HTTPError as exc:
                print(f"Failed to fetch {page_url}: HTTP {exc.code}")
                continue
            except URLError as exc:
                print(f"Failed to fetch {page_url}: {exc}")
                continue
            match = re.search(r'data-page="([^"]+)"', page_text)
            if not match:
                print(f"No data-page found for {page_url}")
                continue
            data_blob = json.loads(html.unescape(match.group(1)))
            series_list = data_blob["props"].get("series") or []
            for series in series_list:
                championship = series.get("championship_name") or ""
                if should_exclude(championship):
                    excluded_championships += 1
                    continue
                series_id = series.get("id")
                team1_name = series.get("team1", {}).get("name", "")
                team2_name = series.get("team2", {}).get("name", "")
                matches = sorted(series.get("matches") or [], key=lambda m: m.get("number", 0))
                for match_info in matches:
                    match_id = match_info.get("id")
                    if not match_id:
                        continue
                    try:
                        match_props = parse_match_page(match_id)
                    except Exception as exc:  # noqa: BLE001
                        print(f"Failed to parse match {match_id}: {exc}")
                        continue
                    init_match = match_props.get("init_match", {})
                    picks = init_match.get("picks") or []
                    is_team1_radiant = init_match.get("is_team1_radiant")
                    team1_heroes = [p["hero"]["name"] for p in picks if p.get("is_radiant") == is_team1_radiant]
                    team2_heroes = [p["hero"]["name"] for p in picks if p.get("is_radiant") != is_team1_radiant]
                    if len(team1_heroes) != 5 or len(team2_heroes) != 5:
                        heroes_list = match_info.get("heroes") or []
                        if heroes_list and len(heroes_list) == 10:
                            team1_heroes = [h["name"] for h in heroes_list if h.get("is_radiant") == is_team1_radiant]
                            team2_heroes = [h["name"] for h in heroes_list if h.get("is_radiant") != is_team1_radiant]
                    if len(team1_heroes) != 5 or len(team2_heroes) != 5:
                        skipped += 1
                        continue
                    delta = compute_delta(hero_data, team1_heroes, team2_heroes)
                    if delta is None:
                        skipped += 1
                        continue
                    is_radiant_won = init_match.get("is_radiant_won")
                    if (is_radiant_won and is_team1_radiant) or ((not is_radiant_won) and (not is_team1_radiant)):
                        winner = team1_name
                    else:
                        winner = team2_name
                    favored_team = team1_name if delta > 0 else team2_name if delta < 0 else "Even"
                    t1_odds, t2_odds, _, _ = extract_odds(match_props)
                    final_seconds, final_minutes_clock = extract_final_game_time(match_props)
                    seconds_value = str(final_seconds) if final_seconds is not None else ""
                    minutes_value = final_minutes_clock or ""
                    writer.writerow([
                        day.isoformat(),
                        championship,
                        series_id,
                        match_info.get("number"),
                        match_id,
                        team1_name,
                        team2_name,
                        "|".join(team1_heroes),
                        "|".join(team2_heroes),
                        winner,
                        f"{delta:.2f}",
                        favored_team,
                        t1_odds or "",
                        t2_odds or "",
                        seconds_value,
                        minutes_value,
                    ])
                    total_rows += 1
            processed_days += 1
            progress_pct = (processed_days / total_days) * 100
            progress_bar = "#" * int(progress_pct // 2)
            bar = progress_bar.ljust(50)
            elapsed = time.time() - start_time
            avg_per_day = elapsed / processed_days if processed_days else 0
            remaining_days = total_days - processed_days
            eta_seconds = avg_per_day * remaining_days
            eta_str = time.strftime("%H:%M:%S", time.gmtime(eta_seconds)) if eta_seconds else "00:00:00"
            sys.stdout.write(
                f"\r[{bar}] {progress_pct:5.1f}% ({processed_days}/{total_days} days) ETA {eta_str}"
            )
            sys.stdout.flush()
            print(
                f"\nProcessed {day.isoformat()} - rows total: {total_rows}, excluded: {excluded_championships}, skipped: {skipped}"
            )
    print(
        f"Done. Rows total: {total_rows}, excluded: {excluded_championships}, skipped: {skipped}. Output -> {output_path}"
    )


if __name__ == "__main__":
    START = dt.date(2022, 1, 1)
    END = dt.date(2023, 1, 1)
    OUTPUT_FILE = Path("hawk_matches_20220101_20230101.csv")
    scrape_range(START, END, OUTPUT_FILE)