-- The test suite refreshes its schema on every run and must never touch the
-- development data, so it gets a database of its own in the same cluster.
CREATE DATABASE weather_station_test;
