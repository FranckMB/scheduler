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
    contract_version: str = Field(default="2.0")
    environment: str = Field(default="dev")
    log_level: str = Field(default="info")
    # Sentry error capture (ENGINE_SENTRY_DSN). Empty = SDK disabled (no-op init):
    # everything is wired now, activated the day the SaaS account exists.
    sentry_dsn: str = Field(default="")
    # Max solves running concurrently across all clubs. 1 preserves the current
    # de-facto serialisation (the event loop no longer blocks — see build_schedule
    # running _solve in a worker thread) while keeping CPU contention bounded.
    max_concurrent_solves: int = Field(default=1, ge=1)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
