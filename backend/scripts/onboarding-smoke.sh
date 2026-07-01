#!/usr/bin/env bash
# Onboarding end-to-end smoke: register a brand-new club, enter the MINIMUM
# (1 team + 1 gym with a slot + 1 coach), generate, and assert a COMPLETED plan.
# Mirrors "I create my account, do the minimum, generate, and get my planning".
# Complements smoke-solver.sh (which reuses the pre-seeded fixtures club).
set -euo pipefail

API="${API_BASE:-http://localhost:8080/api}"
GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
ok()   { printf '%bPASS:%b %s\n' "$GREEN" "$NC" "$1"; }
die()  { printf '%bFAIL:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$1"; }

ARA="ONB$(date +%s)"
info "register new club $ARA"
TOKEN=$(curl -s -X POST "$API/register" -H 'Content-Type: application/json' \
  -d "{\"email\":\"onb-$ARA@smoke.fr\",\"password\":\"password123\",\"firstName\":\"On\",\"lastName\":\"Board\",\"ara\":\"$ARA\",\"club_name\":\"Onb $ARA\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin).get("token",""))')
[[ -n "$TOKEN" ]] || die "register did not return a token"
H=(-H "Authorization: Bearer $TOKEN")
JC=(-H "Content-Type: application/json")

# Isolation: a fresh club must be empty.
COUNT=$(curl -s "$API/teams" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(len(d.get("member",d)))')
[[ "$COUNT" == "0" ]] || die "fresh club is not empty (isolation leak): $COUNT teams"
ok "fresh club is empty (isolation)"

info "enter minimal data"
CAT=$(curl -s "$API/sport_categories" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("member",d)[0]["id"])')
curl -s -o /dev/null "$API/teams" "${H[@]}" "${JC[@]}" -d "{\"name\":\"SM1\",\"sportCategoryId\":\"$CAT\",\"priorityTierId\":1}"
VEN=$(curl -s -X POST "$API/venues" "${H[@]}" "${JC[@]}" -d '{"name":"Gym A","source":"manual"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
curl -s -o /dev/null -X POST "$API/venue_training_slots" "${H[@]}" "${JC[@]}" -d "{\"venueId\":\"$VEN\",\"dayOfWeek\":1,\"startTime\":\"18:00\",\"durationMinutes\":90,\"capacity\":1}"
curl -s -o /dev/null -X POST "$API/coaches" "${H[@]}" "${JC[@]}" -d '{"firstName":"Jean"}'
ok "minimal data created (1 team, 1 gym+slot, 1 coach)"

info "create schedule + generate"
SID=$(curl -s -X POST "$API/schedules" "${H[@]}" "${JC[@]}" -d '{"name":"Mon planning","status":"DRAFT"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/schedules/$SID/generate" "${H[@]}")
[[ "$CODE" == "202" ]] || die "generate returned $CODE"

ONB=$(curl -s "$API/me" "${H[@]}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["club"]["onboardingCompleted"])')
[[ "$ONB" == "True" ]] || die "onboardingCompleted not set on launch"
ok "onboarding completed on launch"

info "poll until COMPLETED"
for i in $(seq 1 60); do
  ST=$(curl -s "$API/schedules/$SID" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("status"),d.get("score"))')
  printf '  [%d] %s\n' "$i" "$ST"
  case "$ST" in
    COMPLETED*) ok "planning COMPLETED — onboarding works end-to-end"; exit 0;;
    FAILED*) die "generation FAILED";;
  esac
  sleep 5
done
die "timeout waiting for COMPLETED"
