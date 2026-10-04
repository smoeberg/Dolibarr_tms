-- 0.9.0: Credit note allocation for enrollments.
-- Links standard Dolibarr credit notes (Facture with type=2) to training enrollments.
-- Tracks which credit notes reduce which enrollment fees.
-- One credit note can be allocated to multiple enrollments (partial credits).
-- One enrollment can receive multiple credit notes (multiple adjustments).

CREATE TABLE IF NOT EXISTS llx_training_creditnote_allocation (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_facture integer NOT NULL,
 fk_facturedet integer NOT NULL,
 fk_soc integer NOT NULL,
 amount decimal(24,8) NOT NULL,
 currency varchar(3) NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active',
 revision integer NOT NULL DEFAULT 1,
 source_hash varchar(64) NOT NULL,
 snapshot_json mediumtext NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_creditnote_entity_id (entity, rowid),
 KEY idx_training_creditnote_enrollment (entity, fk_enrollment),
 KEY idx_training_creditnote_invoice (entity, fk_facture),
 KEY idx_training_creditnote_line (fk_facturedet),
 CONSTRAINT fk_llx_training_creditnote_enrollment FOREIGN KEY (entity, fk_enrollment) REFERENCES llx_training_enrollment(entity, rowid),
 CONSTRAINT fk_llx_training_creditnote_invoice FOREIGN KEY (fk_facture) REFERENCES llx_facture(rowid),
 CONSTRAINT fk_llx_training_creditnote_line FOREIGN KEY (fk_facturedet) REFERENCES llx_facturedet(rowid),
 CONSTRAINT fk_llx_training_creditnote_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;

-- Credit note snapshot: records the credit note state at allocation time
CREATE TABLE IF NOT EXISTS llx_training_creditnote_snapshot (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_allocation integer NOT NULL,
 fk_facture integer NOT NULL,
 invoice_ref varchar(30) NOT NULL,
 amount decimal(24,8) NOT NULL,
 currency varchar(3) NOT NULL,
 date_credit datetime NOT NULL,
 fk_soc integer NOT NULL,
 fk_user_author integer NOT NULL,
 datec datetime NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_creditnote_snapshot (entity, fk_allocation),
 CONSTRAINT fk_llx_training_creditnote_snapshot_allocation FOREIGN KEY (entity, fk_allocation) REFERENCES llx_training_creditnote_allocation(entity, rowid),
 CONSTRAINT fk_llx_training_creditnote_snapshot_invoice FOREIGN KEY (fk_facture) REFERENCES llx_facture(rowid),
 CONSTRAINT fk_llx_training_creditnote_snapshot_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;

-- Credit note balance adjustment tracking
CREATE TABLE IF NOT EXISTS llx_training_creditnote_balance (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_allocation integer NOT NULL,
 adjustment_type varchar(16) NOT NULL,
 amount decimal(24,8) NOT NULL,
 currency varchar(3) NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 KEY idx_training_creditnote_balance_enrollment (entity, fk_enrollment),
 KEY idx_training_creditnote_balance_allocation (entity, fk_allocation),
 CONSTRAINT fk_llx_training_creditnote_balance_enrollment FOREIGN KEY (entity, fk_enrollment) REFERENCES llx_training_enrollment(entity, rowid),
 CONSTRAINT fk_llx_training_creditnote_balance_allocation FOREIGN KEY (entity, fk_allocation) REFERENCES llx_training_creditnote_allocation(entity, rowid)
) ENGINE=InnoDB;
