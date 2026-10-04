-- 0.9.0: Order line allocation for enrollments.
-- Links standard Dolibarr order lines (CommandeDet) to training sessions and enrollments.
-- Same pattern as invoice line allocation (llx_training_billing_line).
-- Never modifies standard order tables; only creates read-only references.

CREATE TABLE IF NOT EXISTS llx_training_order_line (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_commande integer NOT NULL,
 fk_commandedet integer NOT NULL,
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
 UNIQUE KEY uk_training_order_standard_line (fk_commandedet),
 UNIQUE KEY uk_training_order_entity_id (entity, rowid),
 KEY idx_training_order_session (entity, fk_session),
 KEY idx_training_order_commande (entity, fk_commande),
 CONSTRAINT fk_llx_training_order_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_order_commande FOREIGN KEY (fk_commande) REFERENCES llx_commande(rowid),
 CONSTRAINT fk_llx_training_order_standard_line FOREIGN KEY (fk_commandedet) REFERENCES llx_commandedet(rowid),
 CONSTRAINT fk_llx_training_order_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_order_allocation (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_order_line integer NOT NULL,
 fk_enrollment integer NOT NULL,
 weight integer NOT NULL,
 amount_ht decimal(24,8) NOT NULL,
 amount_ttc decimal(24,8) NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_order_enrollment (entity, fk_order_line, fk_enrollment),
 KEY idx_training_order_allocation_enrollment (entity, fk_enrollment),
 CONSTRAINT fk_llx_training_order_allocation_line FOREIGN KEY (entity, fk_order_line) REFERENCES llx_training_order_line(entity, rowid),
 CONSTRAINT fk_llx_training_order_allocation_enrollment FOREIGN KEY (fk_enrollment) REFERENCES llx_training_enrollment(rowid)
) ENGINE=InnoDB;
