-- Additive 0.5.0. Identities remain in Dolibarr's standard user table.
CREATE TABLE IF NOT EXISTS llx_training_trainer (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_user integer NOT NULL,
 active integer NOT NULL DEFAULT 1,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_trainer_user (entity, fk_user),
 UNIQUE KEY uk_training_trainer_entity_id (entity, rowid),
 CONSTRAINT fk_llx_training_trainer_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_trainer_assignment (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_trainer integer NOT NULL,
 status varchar(16) NOT NULL,
 revision integer NOT NULL DEFAULT 1,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_trainer_assignment (entity, fk_session, fk_trainer),
 KEY idx_training_trainer_assignment_access (entity, fk_trainer, status),
 CONSTRAINT fk_llx_training_trainer_assignment_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_trainer_assignment_profile FOREIGN KEY (entity, fk_trainer) REFERENCES llx_training_trainer(entity, rowid)
) ENGINE=InnoDB;
