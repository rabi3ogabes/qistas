#!/usr/bin/env bash
# usage: shot.sh "<url>" out.png [width] [height]   — headless Chrome screenshot of a (local) page
CHROME="/c/Program Files/Google/Chrome/Application/chrome.exe"
[ -x "$CHROME" ] || CHROME="/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"
"$CHROME" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 --window-size=${3:-1440},${4:-900} --virtual-time-budget=6000 --screenshot="$2" "$1" 2>/dev/null
