-- Background job queue for iDoklad import (avoids Cloudflare 502 on long imports)
CREATE TABLE IF NOT EXISTS idoklad_import_jobs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    admin_id    INT NOT NULL,
    status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    params      JSON NOT NULL,
    result      JSON NULL,
    log         TEXT NULL,
    error       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier_status (supplier_id, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
