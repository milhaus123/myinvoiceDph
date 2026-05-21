-- Add cancelled status to idoklad_import_jobs (MariaDB syntax)
ALTER TABLE idoklad_import_jobs
CHANGE COLUMN status status ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued';

-- Add unique constraint to prevent duplicate imports with same params (within last hour)
-- This prevents creating duplicate import jobs for the same supplier/years/sections combination
ALTER TABLE idoklad_import_jobs
ADD INDEX idx_import_params (supplier_id, params(255));
