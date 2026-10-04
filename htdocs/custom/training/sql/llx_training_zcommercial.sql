-- 0.8.0: current commercial role links, not an accepted agreement or payment.
CREATE TABLE IF NOT EXISTS llx_training_enrollment_commercial (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_buyer integer NULL,
 fk_payer integer NULL,
 fk_employer integer NULL,
 revision integer NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_commercial_enrollment (entity, fk_enrollment),
 CONSTRAINT fk_llx_training_commercial_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_commercial_enrollment FOREIGN KEY (fk_enrollment) REFERENCES llx_training_enrollment(rowid),
 CONSTRAINT fk_llx_training_commercial_buyer FOREIGN KEY (fk_buyer) REFERENCES llx_societe(rowid),
 CONSTRAINT fk_llx_training_commercial_payer FOREIGN KEY (fk_payer) REFERENCES llx_societe(rowid),
 CONSTRAINT fk_llx_training_commercial_employer FOREIGN KEY (fk_employer) REFERENCES llx_societe(rowid)
) ENGINE=InnoDB;
