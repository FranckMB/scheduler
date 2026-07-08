# Fixture assets

Optional binary assets used when loading the dev fixtures (`make fixtures`).

## `bccl-logo.png`

Drop a PNG here named exactly `bccl-logo.png` (≤ 500 KB, same limits as the upload
endpoint) to ship a default logo for the seeded club **B CHARPENNES CROIX LUIZET**.

`BasketballInit` stores it via `LogoStorage` and sets the club's `logoUrl` to the
public serve route (`/api/clubs/{id}/logo?v=<hash>`), exactly like a manual upload.
If the file is absent the fixture skips the logo silently — it never fails on it.
