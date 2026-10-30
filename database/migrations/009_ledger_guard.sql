-- 009_ledger_guard: database-level immutability for posted ledger entries (BILLING_RULES section 6).
-- Single-statement trigger bodies (no BEGIN/END) so the simple migrator can run them.
-- Requires the TRIGGER privilege. If the app user lacks it, run this file once as a privileged user.

DROP TRIGGER IF EXISTS trg_ledger_no_update;

CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: post a reversing entry';

DROP TRIGGER IF EXISTS trg_ledger_no_delete;

CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: post a reversing entry';
