PRAGMA user_version = 0;
CREATE TABLE places (
    id TEXT PRIMARY KEY NOT NULL,
    parent_id TEXT REFERENCES places(id) DEFERRABLE INITIALLY DEFERRED,
    country_code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    local_type TEXT,
    payload TEXT NOT NULL
);
CREATE INDEX places_parent ON places(parent_id);
CREATE INDEX places_country_type ON places(country_code, type);
CREATE TABLE names (place_id TEXT NOT NULL, compact TEXT NOT NULL);
CREATE INDEX names_compact ON names(compact, place_id);
CREATE TABLE postal_codes (place_id TEXT NOT NULL, code TEXT NOT NULL, compact TEXT NOT NULL);
CREATE INDEX postal_compact ON postal_codes(compact, place_id);
CREATE VIRTUAL TABLE place_search USING fts5(place_id UNINDEXED, context, tokenize='unicode61 remove_diacritics 2');
CREATE TABLE boundaries (
    place_id TEXT NOT NULL,
    min_x REAL NOT NULL,
    max_x REAL NOT NULL,
    min_y REAL NOT NULL,
    max_y REAL NOT NULL,
    wraps INTEGER NOT NULL,
    geometry TEXT NOT NULL
);
CREATE INDEX boundaries_latitude ON boundaries(min_y, max_y);
