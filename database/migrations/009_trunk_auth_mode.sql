-- 009: trunk auth mode for IP peering vs outbound REGISTER (Magnus-style)

ALTER TABLE carrier_trunks
    ADD COLUMN auth_mode VARCHAR(16) NOT NULL DEFAULT 'ip'
        COMMENT 'ip = peer by IP (no REGISTER); register = outbound REGISTER to provider; invite = digest on INVITE only'
        AFTER auth_secret_enc,
    ADD COLUMN outbound_proxy VARCHAR(190) NULL
        COMMENT 'Optional SIP proxy URI host for REGISTER/INVITE'
        AFTER auth_mode,
    ADD COLUMN register_status VARCHAR(32) NULL
        COMMENT 'last known registrant state from OpenSIPS (informational)'
        AFTER outbound_proxy,
    ADD COLUMN register_checked_at DATETIME(3) NULL
        AFTER register_status;
