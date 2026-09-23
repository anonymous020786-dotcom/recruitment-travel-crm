-- Migration 0011 — one departure record per application.
-- TravelService::recordDeparture() creates the row when a candidate flies; the
-- pipeline and placement queries join it on application_id and rely on there
-- being at most one. NULL application_ids (none are written today) stay allowed.

ALTER TABLE departure_records
    ADD UNIQUE KEY uq_departure_application (application_id);
