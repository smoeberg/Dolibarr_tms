-- 0.4.0: loaded alphabetically after catalog and session tables.
-- Standard invoice tables are foreign-key targets, never economic masters in Training.
CREATE TABLE IF NOT EXISTS llx_training_billing_line (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_facture integer NOT NULL,
 fk_facturedet integer NOT NULL,
 fk_soc integer NOT NULL,
 currency varchar(3) NOT NULL,
 source_hash varchar(64) NOT NULL,
 snapshot_json mediumtext NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active',
 revision integer NOT NULL DEFAULT 1,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_billing_standard_line (fk_facturedet),
 UNIQUE KEY uk_training_billing_entity_id (entity, rowid),
 KEY idx_training_billing_session (entity, fk_session),
 CONSTRAINT fk_llx_training_billing_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_billing_invoice FOREIGN KEY (fk_facture) REFERENCES llx_facture(rowid),
 CONSTRAINT fk_llx_training_billing_standard_line FOREIGN KEY (fk_facturedet) REFERENCES llx_facturedet(rowid),
 CONSTRAINT fk_llx_training_billing_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_billing_allocation (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_billing_line integer NOT NULL,
 fk_enrollment integer NOT NULL,
 weight integer NOT NULL,
 amount_ht decimal(24,8) NOT NULL,
 amount_ttc decimal(24,8) NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_billing_enrollment (entity, fk_billing_line, fk_enrollment),
 KEY idx_training_billing_allocation_enrollment (entity, fk_enrollment),
 CONSTRAINT fk_llx_training_billing_allocation_line FOREIGN KEY (entity, fk_billing_line) REFERENCES llx_training_billing_line(entity, rowid),
 CONSTRAINT fk_llx_training_billing_allocation_enrollment FOREIGN KEY (fk_enrollment) REFERENCES llx_training_enrollment(rowid)
) ENGINE=InnoDB;
