CREATE TABLE IF NOT EXISTS llx_training_native_link (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL,
  source_type varchar(32) NOT NULL,
  fk_source integer NOT NULL,
  target_type varchar(32) NOT NULL,
  fk_target integer NOT NULL,
  identity_key varchar(191) NOT NULL,
  datec datetime NOT NULL,
  fk_user_author integer NOT NULL,
  datem timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_mod integer NULL,
  UNIQUE KEY uk_training_native_link_source (entity, source_type, fk_source, target_type),
  UNIQUE KEY uk_training_native_link_target (entity, target_type, fk_target),
  UNIQUE KEY uk_training_native_link_identity (identity_key),
  KEY idx_training_native_link_source (entity, source_type, fk_source),
  KEY idx_training_native_link_target (entity, target_type, fk_target)
) ENGINE=InnoDB;