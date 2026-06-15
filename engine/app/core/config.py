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


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
