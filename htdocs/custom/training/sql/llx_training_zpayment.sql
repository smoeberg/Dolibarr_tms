-- 0.9.0: Payment allocation for enrollments.
-- Links standard Dolibarr payments (Paiement) to training enrollments.
-- Tracks which payments cover which enrollment fees.
-- One payment can be allocated to multiple enrollments (partial payments).
-- One enrollment can receive multiple payments (installments).

CREATE TABLE IF NOT EXISTS llx_training_payment_allocation (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_paiement integer NOT NULL,
 fk_soc integer NOT NULL,
 amount decimal(24,8) NOT NULL,
 currency varchar(3) NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_payment_entity_id (entity, rowid),
    UNIQUE KEY uk_training_payment_enrollment_paiement (entity, fk_enrollment, fk_paiement),
 KEY idx_training_payment_enrollment (entity, fk_enrollment),
 KEY idx_training_payment_paiement (entity, fk_paiement),
 CONSTRAINT fk_llx_training_payment_enrollment FOREIGN KEY (entity, fk_enrollment) REFERENCES llx_training_enrollment(entity, rowid),
 CONSTRAINT fk_llx_training_payment_paiement FOREIGN KEY (fk_paiement) REFERENCES llx_paiement(rowid),
 CONSTRAINT fk_llx_training_payment_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;

-- Payment snapshot: records the payment state at allocation time
CREATE TABLE IF NOT EXISTS llx_training_payment_snapshot (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_allocation integer NOT NULL,
 fk_paiement integer NOT NULL,
 paiement_ref varchar(30) NOT NULL,
 amount decimal(24,8) NOT NULL,
 currency varchar(3) NOT NULL,
 datep datetime NOT NULL,
 fk_soc integer NOT NULL,
 fk_user_author integer NOT NULL,
 datec datetime NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_payment_snapshot (entity, fk_allocation),
 CONSTRAINT fk_llx_training_payment_snapshot_allocation FOREIGN KEY (entity, fk_allocation) REFERENCES llx_training_payment_allocation(entity, rowid),
 CONSTRAINT fk_llx_training_payment_snapshot_paiement FOREIGN KEY (fk_paiement) REFERENCES llx_paiement(rowid),
 CONSTRAINT fk_llx_training_payment_snapshot_customer FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;
