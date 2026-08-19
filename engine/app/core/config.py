from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(".env",),
        env_file_encoding="utf-8",
        env_prefix="ENGINE_",
        extra="ignore",
    )

    app_name: str = Field(default="engine")
    app_version: str = Field(default="1.0")
    environment: str = Field(default="dev")
    log_level: str = Field(default="info")
    # Sentry error capture (ENGINE_SENTRY_DSN). Empty = SDK disabled (no-op init):
    # everything is wired now, activated the day the SaaS account exists.
    sentry_dsn: str = Field(default="")
    # Max solves running concurrently across all clubs. 1 preserves the current
    # de-facto serialisation (the event loop no longer blocks — see build_schedule
    # running _solve in a worker thread) while keeping CPU contention bounded.
    max_concurrent_solves: int = Field(default=1, ge=1)
    # AUD-ENG-30 — budget SÉPARÉ pour le rail matchs. `/place-matches` est SYNCHRONE
    # (ADR-0003 : pas de Messenger, pas de Mercure — le gestionnaire attend la réponse
    # HTTP) et dure ~3 s, quand `/generate` peut tenir 600 s. Les faire partager un
    # sémaphore de 1 revenait à faire attendre un appel synchrone derrière un solve
    # hebdomadaire : timeout côté gestionnaire, sur une opération de trois secondes.
    #
    # Le verrou de club de `/place-matches` était DÉJÀ préfixé pour éviter cela
    # (`matches:{club_id}`) — le sémaphore partagé défaisait cette intention.
    #
    # Coût assumé : au pire 1 génération + 1 placement en parallèle au lieu d'un solve
    # seul. Le placement est un petit problème daté, sans commune mesure avec le solve
    # hebdomadaire ; le CPU reste borné, il n'est plus borné à UN.
    max_concurrent_placements: int = Field(default=1, ge=1)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
