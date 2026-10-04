-- 0.9.0: Enrollment price agreement snapshot at confirmation time.
-- One snapshot per enrollment, set once at confirmation. Never updated by catalog changes.
CREATE TABLE IF NOT EXISTS llx_training_enrollment_price (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_session integer NOT NULL,
 fk_product integer NOT NULL,
 price_ht decimal(24,8) NOT NULL,
 price_ttc decimal(24,8) NOT NULL,
 tva_tx decimal(16,8) NOT NULL,
 currency varchar(3) NOT NULL,
 unit_price_ht decimal(24,8) NOT NULL,
 unit_price_ttc decimal(24,8) NOT NULL,
 qty decimal(24,8) NOT NULL DEFAULT 1,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_enrollment_price (entity, fk_enrollment),
 KEY idx_training_price_session (entity, fk_session),
 CONSTRAINT fk_llx_training_price_enrollment FOREIGN KEY (entity, fk_enrollment) REFERENCES llx_training_enrollment(entity, rowid),
 CONSTRAINT fk_llx_training_price_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_price_product FOREIGN KEY (fk_product) REFERENCES llx_product(rowid)
) ENGINE=InnoDB;
