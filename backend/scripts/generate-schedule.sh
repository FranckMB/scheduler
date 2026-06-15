#!/usr/bin/env bash
set -euo pipefail

API_BASE="http://localhost:8080/api"
CLUB_ID="11111111-1111-1111-1111-111111111111"
POLL_INTERVAL=5
TIMEOUT_SECONDS=300

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
BLUE=$'\033[0;34m'
NC=$'\033[0m'

SCHEDULE_NAME="Planning Test $(date +%Y-%m-%d_%H:%M:%S)"

usage() {
  cat <<EOF
Usage: $(basename "$0") [--name "Schedule name"]

Options:
  --name NAME   Set the schedule name
  --help, -h    Show this help

Example:
  $(basename "$0") --name "Planning BCCL 2025-2026"
EOF
}

die() {
  printf '%bErreur:%b %s\n' "$RED" "$NC" "$1" >&2
  exit 1
}

info() {
  printf '%b%s%b\n' "$GREEN" "$1" "$NC"
}

warn() {
  printf '%b%s%b\n' "$YELLOW" "$1" "$NC"
}

http_request() {
  local method="$1"
  local url="$2"
  local data="${3-}"
  local body_file err_file
  body_file=$(mktemp)
  err_file=$(mktemp)

  if [[ -n "${data-}" ]]; then
    if ! HTTP_STATUS=$(curl -sS -o "$body_file" -w '%{http_code}' -X "$method" -H 'Content-Type: application/json' --data "$data" "$url" 2>"$err_file"); then
      local err_msg
      err_msg=$(<"$err_file")
      rm -f "$body_file" "$err_file"
      die "Backend unreachable while calling $method $url: ${err_msg:-curl failed}"
    fi
  else
    if ! HTTP_STATUS=$(curl -sS -o "$body_file" -w '%{http_code}' -X "$method" "$url" 2>"$err_file"); then
      local err_msg
      err_msg=$(<"$err_file")
      rm -f "$body_file" "$err_file"
      die "Backend unreachable while calling $method $url: ${err_msg:-curl failed}"
    fi
  fi

  HTTP_BODY=$(<"$body_file")
  rm -f "$body_file" "$err_file"
}

extract_id() {
  python3 -c '
import json
import sys

data = json.load(sys.stdin)
if not isinstance(data, dict):
    raise SystemExit(1)

value = data.get("id")
if value in (None, "") and "@id" in data:
    value = str(data["@id"]).rstrip("/").split("/")[-1]

if value in (None, ""):
    raise SystemExit(1)

print(value)
' 
}

extract_items() {
  python3 -c '
import json
import sys

data = json.load(sys.stdin)
if isinstance(data, list):
    items = data
elif isinstance(data, dict):
    items = data.get("hydra:member") or data.get("member") or data.get("items") or data.get("data") or []
else:
    items = []

if not isinstance(items, list):
    items = []

for item in items:
    if isinstance(item, (dict, list)):
        print(json.dumps(item, ensure_ascii=False))
' 
}

day_label() {
  case "$1" in
    0|7) printf 'Dimanche' ;;
    1) printf 'Lundi' ;;
    2) printf 'Mardi' ;;
    3) printf 'Mercredi' ;;
    4) printf 'Jeudi' ;;
    5) printf 'Vendredi' ;;
    6) printf 'Samedi' ;;
    *) printf 'J%s' "$1" ;;
  esac
}

print_slots() {
  local slots_json="$1"
  local line_count=0
  local item

  printf '%bSlots créés%b\n' "$BLUE" "$NC"
  printf '%-24s %-10s %-8s %-10s %-36s\n' "Équipe" "Jour" "Heure" "Durée" "Salle"
  printf '%s\n' "-------------------------------------------------------------------------------------"

  while IFS= read -r item; do
    [[ -n "$item" ]] || continue
    local team_id day_of_week start_time duration venue
    team_id=$(extract_field_from_json "$item" "teamId")
    day_of_week=$(extract_field_from_json "$item" "dayOfWeek")
    start_time=$(extract_field_from_json "$item" "startTime")
    duration=$(extract_field_from_json "$item" "durationMinutes")
    venue=$(extract_field_from_json "$item" "venueId")

    start_time=${start_time%:00}
    printf '%-24s %-10s %-8s %-10s %-36s\n' "$team_id" "$(day_label "$day_of_week")" "$start_time" "${duration} min" "$venue"
    line_count=$((line_count + 1))
  done < <(printf '%s' "$slots_json" | extract_items)

  if [[ "$line_count" -eq 0 ]]; then
    warn "Aucun créneau retourné par l'API."
  fi
}

print_diagnostics() {
  local diagnostics_json="$1"
  local item

  printf '%bPLANNING ÉCHOUÉ%b\n' "$RED" "$NC"
  printf '%bDiagnostics%b\n' "$BLUE" "$NC"

  local has_items=0
  while IFS= read -r item; do
    [[ -n "$item" ]] || continue
    has_items=1
    local type severity message
    type=$(extract_field_from_json "$item" "type")
    severity=$(extract_field_from_json "$item" "severity")
    message=$(extract_field_from_json "$item" "message")
    printf '  - [%s] %s: %s\n' "$severity" "$type" "$message"
  done < <(printf '%s' "$diagnostics_json" | extract_items)

  if [[ "$has_items" -eq 0 ]]; then
    warn "Aucun diagnostic retourné par l'API."
  fi
}

extract_field_from_json() {
  local json="$1"
  local field="$2"
  python3 -c '
import json
import sys

field = sys.argv[1]
data = json.load(sys.stdin)
if not isinstance(data, dict):
    raise SystemExit(1)

value = data.get(field)
if value is None:
    raise SystemExit(1)

if isinstance(value, dict):
    for key in ("name", "label", "title", "value", "code", "id", "@id"):
        nested = value.get(key)
        if nested not in (None, ""):
            value = nested
            break

if value is None:
    raise SystemExit(1)

print(value)
' "$field" <<<"$json"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --name)
      [[ $# -ge 2 ]] || die "--name requires a value"
      SCHEDULE_NAME="$2"
      shift 2
      ;;
    --name=*)
      SCHEDULE_NAME="${1#*=}"
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      die "Unknown option: $1"
      ;;
  esac
done

schedule_payload=$(python3 -c 'import json,sys; print(json.dumps({"name": sys.argv[1], "status": "DRAFT"}, ensure_ascii=False))' "$SCHEDULE_NAME")

info "Création du schedule: $SCHEDULE_NAME"
http_request POST "$API_BASE/schedules" "$schedule_payload"
case "$HTTP_STATUS" in
  200|201)
    ;;
  404)
    die "Endpoint /schedules introuvable (HTTP 404). Le backend est-il bien démarré ?"
    ;;
  *)
    die "Échec de création du schedule (HTTP $HTTP_STATUS): $HTTP_BODY"
    ;;
esac

if ! SCHEDULE_ID=$(extract_id <<<"$HTTP_BODY"); then
  die "Réponse JSON invalide lors de la création du schedule"
fi

info "Schedule créé: $SCHEDULE_ID"

info "Déclenchement de la génération"
http_request POST "$API_BASE/schedules/$SCHEDULE_ID/generate"
case "$HTTP_STATUS" in
  200|202)
    ;;
  404)
    die "Endpoint /schedules/$SCHEDULE_ID/generate introuvable (HTTP 404)"
    ;;
  *)
    die "Échec du déclenchement (HTTP $HTTP_STATUS): $HTTP_BODY"
    ;;
esac

info "Génération lancée"

deadline=$((SECONDS + TIMEOUT_SECONDS))
attempt=0
current_status=""
schedule_score=""

while :; do
  if [[ "$SECONDS" -ge "$deadline" ]]; then
    die "Timeout après 5 minutes en attendant la génération"
  fi

  attempt=$((attempt + 1))
  http_request GET "$API_BASE/schedules/$SCHEDULE_ID"
  case "$HTTP_STATUS" in
    200)
      ;;
    404)
      die "Schedule $SCHEDULE_ID introuvable pendant le polling (HTTP 404)"
      ;;
    *)
      die "Échec du polling (HTTP $HTTP_STATUS): $HTTP_BODY"
      ;;
  esac

  if ! current_status=$(extract_field_from_json "$HTTP_BODY" "status"); then
    die "Réponse JSON invalide pendant le polling"
  fi

  schedule_score=$(python3 -c '
import json
import sys

data = json.load(sys.stdin)
value = data.get("score")
if value in (None, ""):
    print("")
else:
    print(value)
' <<<"$HTTP_BODY")

  if [[ -n "$schedule_score" ]]; then
    printf '%b[%d]%b Status: %s | Score: %s\n' "$BLUE" "$attempt" "$NC" "$current_status" "$schedule_score"
  else
    printf '%b[%d]%b Status: %s\n' "$BLUE" "$attempt" "$NC" "$current_status"
  fi

  case "$current_status" in
    COMPLETED|FAILED)
      break
      ;;
  esac

  if [[ "$SECONDS" -ge "$deadline" ]]; then
    die "Timeout après 5 minutes en attendant la génération"
  fi
  sleep "$POLL_INTERVAL"
done

if [[ "$current_status" == "COMPLETED" ]]; then
  info "PLANNING COMPLETÉ"
  slots_url_base="$API_BASE/schedule-slot-templates?scheduleId=$SCHEDULE_ID"
  http_request GET "$slots_url_base"
  if [[ "$HTTP_STATUS" == "404" ]]; then
    http_request GET "$API_BASE/schedule_slot_templates?scheduleId=$SCHEDULE_ID"
  fi

  case "$HTTP_STATUS" in
    200)
      ;;
    404)
      die "Impossible de récupérer les créneaux (HTTP 404)"
      ;;
    *)
      die "Impossible de récupérer les créneaux (HTTP $HTTP_STATUS): $HTTP_BODY"
      ;;
  esac

  print_slots "$HTTP_BODY"
  exit 0
fi

print_diagnostics "$HTTP_BODY"
exit 1
