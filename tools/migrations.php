<?php
/**
 * SubjectsPlus database migrations (shared by tools/migrate.php and Admin → Database Updates).
 *
 * Each migration runs once; applied ones are recorded in the `jgu_schema_migration` table
 * (table name kept from the 4.6 maintenance branch so existing sites upgrade without changes).
 * Statements whose change is already in place (column, index, table or key exists) are skipped,
 * so a migration that stopped half-way can simply be run again.
 */

if ( PHP_SAPI !== 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	http_response_code( 403 );
	exit;
}

function sp_migrations() {
	/** Ordered list of migrations: id => [description, list of SQL statements] */
	$migrations = array(
	
		'2026_10_01_login_attempt' => array(
			'Table for login throttling (failed-login lockout)',
			array(
				"CREATE TABLE IF NOT EXISTS `login_attempt` (
					`login_attempt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`email` VARCHAR(255) NOT NULL,
					`ip` VARCHAR(45) NOT NULL,
					`success` TINYINT(1) NOT NULL DEFAULT 0,
					`attempted_at` DATETIME NOT NULL,
					PRIMARY KEY (`login_attempt_id`),
					KEY `idx_login_attempt_email_time` (`email`, `attempted_at`),
					KEY `idx_login_attempt_ip_time` (`ip`, `attempted_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
			),
		),
	
		'2026_10_02_staff_widen_personal_fields' => array(
			'Widen staff personal-data columns so encrypted values fit (zip was 30 chars; encrypted values are 44+)',
			array(
				"ALTER TABLE `staff`
					MODIFY `emergency_contact_name` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `emergency_contact_relation` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `emergency_contact_phone` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `street_address` VARCHAR(512) NULL DEFAULT NULL,
					MODIFY `city` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `state` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `zip` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `home_phone` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `cell_phone` VARCHAR(255) NULL DEFAULT NULL,
					MODIFY `lat_long` VARCHAR(255) NULL DEFAULT NULL",
			),
		),
	
		'2026_10_03_staff_password_length' => array(
			'Ensure the password column fits modern hashes (bcrypt 60, argon2id ~100 chars)',
			array(
				"ALTER TABLE `staff` MODIFY `password` VARCHAR(255) NULL DEFAULT NULL",
			),
		),
	
		'2026_10_12_access_providers' => array(
			'Off-campus access providers (EZproxy, OpenAthens, RemoteXs/RemoteLog, MyLOFT, custom) and domain rules',
			array(
				"CREATE TABLE IF NOT EXISTS `access_provider` (
					`provider_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`name` VARCHAR(100) NOT NULL,
					`kind` VARCHAR(30) NOT NULL DEFAULT 'custom',
					`link_template` VARCHAR(1000) NOT NULL DEFAULT '',
					`portal_url` VARCHAR(1000) NOT NULL DEFAULT '',
					`note` VARCHAR(500) NOT NULL DEFAULT '',
					`is_default` TINYINT(1) NOT NULL DEFAULT 0,
					`active` TINYINT(1) NOT NULL DEFAULT 1,
					`updated_at` DATETIME NULL DEFAULT NULL,
					PRIMARY KEY (`provider_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `access_rule` (
					`rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`provider_id` INT UNSIGNED NOT NULL,
					`domain` VARCHAR(255) NOT NULL,
					PRIMARY KEY (`rule_id`),
					UNIQUE KEY `uq_access_rule_domain` (`domain`),
					KEY `idx_access_rule_provider` (`provider_id`),
					CONSTRAINT `fk_access_rule_provider` FOREIGN KEY (`provider_id`) REFERENCES `access_provider` (`provider_id`) ON DELETE CASCADE
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
			),
		),
	
		'2026_10_13_record_settings_workflow' => array(
			'Admin-managed formats, access restrictions and statuses; custom fields; workflow (transitions, rules, history, requests)',
			array(
				"ALTER TABLE `format` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0, ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1",
				"UPDATE `format` SET sort_order = format_id",
				"ALTER TABLE `restrictions` ADD COLUMN `behaviour` VARCHAR(20) NOT NULL DEFAULT 'proxy', ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0, ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1",
				// meaning of the four built-in values in SubjectsPlus 4.6
				"UPDATE `restrictions` SET behaviour = CASE restrictions_id WHEN 1 THEN 'direct' WHEN 2 THEN 'proxy' WHEN 3 THEN 'campus' WHEN 4 THEN 'noproxy' ELSE 'proxy' END, sort_order = restrictions_id",
				"ALTER TABLE `location` ADD COLUMN `eres_display_saved` VARCHAR(1) NULL DEFAULT NULL",
				"CREATE TABLE IF NOT EXISTS `record_status_option` (
					`status_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`name` VARCHAR(100) NOT NULL,
					`stage` VARCHAR(20) NOT NULL DEFAULT 'active',
					`is_public` TINYINT(1) NOT NULL DEFAULT 0,
					`is_initial` TINYINT(1) NOT NULL DEFAULT 0,
					`color` VARCHAR(7) NOT NULL DEFAULT '#777777',
					`description` VARCHAR(255) NOT NULL DEFAULT '',
					`sort_order` INT NOT NULL DEFAULT 0,
					`active` TINYINT(1) NOT NULL DEFAULT 1,
					PRIMARY KEY (`status_id`),
					UNIQUE KEY `uq_status_name` (`name`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				// the four statuses SubjectsPlus 4.6 shipped with; 'Active' and 'Trial' were the ones shown publicly
				"INSERT IGNORE INTO `record_status_option` (name, stage, is_public, is_initial, color, sort_order) VALUES
					('Active','active',1,1,'#2e7d32',10),('Trial','trial',1,0,'#1565c0',20),
					('Inactive','inactive',0,0,'#757575',30),('Rejected','rejected',0,0,'#c62828',40)",
				// keep any other status names already used on records (4.6 only listed 'Active' ones in subject lists)
				"INSERT IGNORE INTO `record_status_option` (name, stage, is_public, sort_order)
					SELECT DISTINCT TRIM(record_status), 'active', 0, 90 FROM location WHERE record_status IS NOT NULL AND TRIM(record_status) <> ''",
				"CREATE TABLE IF NOT EXISTS `workflow_transition` (
					`transition_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`from_status_id` INT UNSIGNED NULL DEFAULT NULL,
					`to_status_id` INT UNSIGNED NOT NULL,
					`label` VARCHAR(100) NOT NULL DEFAULT '',
					`role` VARCHAR(30) NOT NULL DEFAULT 'records',
					`require_comment` TINYINT(1) NOT NULL DEFAULT 0,
					`notify` TINYINT(1) NOT NULL DEFAULT 0,
					`sort_order` INT NOT NULL DEFAULT 0,
					PRIMARY KEY (`transition_id`),
					KEY `idx_wt_from` (`from_status_id`),
					CONSTRAINT `fk_wt_from` FOREIGN KEY (`from_status_id`) REFERENCES `record_status_option` (`status_id`) ON DELETE CASCADE,
					CONSTRAINT `fk_wt_to` FOREIGN KEY (`to_status_id`) REFERENCES `record_status_option` (`status_id`) ON DELETE CASCADE
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `record_field` (
					`field_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`label` VARCHAR(150) NOT NULL,
					`type` VARCHAR(20) NOT NULL DEFAULT 'text',
					`options` TEXT NULL,
					`help` VARCHAR(255) NOT NULL DEFAULT '',
					`required` TINYINT(1) NOT NULL DEFAULT 0,
					`is_public` TINYINT(1) NOT NULL DEFAULT 0,
					`currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
					`sort_order` INT NOT NULL DEFAULT 0,
					`active` TINYINT(1) NOT NULL DEFAULT 1,
					PRIMARY KEY (`field_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `record_field_value` (
					`title_id` BIGINT(20) NOT NULL,
					`field_id` INT UNSIGNED NOT NULL,
					`value` TEXT NULL,
					PRIMARY KEY (`title_id`, `field_id`),
					KEY `idx_rfv_field` (`field_id`),
					CONSTRAINT `fk_rfv_title` FOREIGN KEY (`title_id`) REFERENCES `title` (`title_id`) ON DELETE CASCADE,
					CONSTRAINT `fk_rfv_field` FOREIGN KEY (`field_id`) REFERENCES `record_field` (`field_id`) ON DELETE CASCADE
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `workflow_rule` (
					`rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
					`name` VARCHAR(150) NOT NULL DEFAULT '',
					`status_id` INT UNSIGNED NOT NULL,
					`date_source` VARCHAR(40) NOT NULL,
					`offset_days` INT NOT NULL DEFAULT 0,
					`to_status_id` INT UNSIGNED NULL DEFAULT NULL,
					`notify` TINYINT(1) NOT NULL DEFAULT 1,
					`notify_emails` VARCHAR(500) NOT NULL DEFAULT '',
					`active` TINYINT(1) NOT NULL DEFAULT 1,
					PRIMARY KEY (`rule_id`),
					CONSTRAINT `fk_wr_status` FOREIGN KEY (`status_id`) REFERENCES `record_status_option` (`status_id`) ON DELETE CASCADE,
					CONSTRAINT `fk_wr_to` FOREIGN KEY (`to_status_id`) REFERENCES `record_status_option` (`status_id`) ON DELETE SET NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `workflow_rule_log` (
					`rule_id` INT UNSIGNED NOT NULL,
					`title_id` BIGINT(20) NOT NULL,
					`fired_for` DATE NOT NULL,
					`fired_at` DATETIME NOT NULL,
					PRIMARY KEY (`rule_id`, `title_id`, `fired_for`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `workflow_history` (
					`history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`title_id` BIGINT(20) NOT NULL,
					`from_status` VARCHAR(100) NULL DEFAULT NULL,
					`to_status` VARCHAR(100) NOT NULL,
					`staff_id` INT NULL DEFAULT NULL,
					`via` VARCHAR(20) NOT NULL DEFAULT 'staff',
					`comment` TEXT NULL,
					`created_at` DATETIME NOT NULL,
					PRIMARY KEY (`history_id`),
					KEY `idx_wh_title` (`title_id`, `created_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `resource_request` (
					`request_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`title_id` BIGINT(20) NULL DEFAULT NULL,
					`name` VARCHAR(150) NOT NULL,
					`email` VARCHAR(255) NOT NULL,
					`department` VARCHAR(150) NOT NULL DEFAULT '',
					`justification` TEXT NULL,
					`created_at` DATETIME NOT NULL,
					PRIMARY KEY (`request_id`),
					KEY `idx_rr_title` (`title_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
			),
		),

		'2026_10_14_stats_records' => array(
			'Usage reports: link clicks matched to e-resource records, faster date queries',
			array(
				"ALTER TABLE `stats` ADD COLUMN `title_id` BIGINT NULL DEFAULT NULL",
				"ALTER TABLE `stats` ADD INDEX `idx_stats_date` (`date`)",
				"ALTER TABLE `stats` ADD INDEX `idx_stats_title` (`title_id`)",
			),
		),

		'2026_10_15_guide_features' => array(
			'Guides: box show/hide dates, review reminders',
			array(
				"CREATE TABLE IF NOT EXISTS `pluslet_schedule` (
					`pluslet_id` BIGINT NOT NULL,
					`show_from` DATE NULL DEFAULT NULL,
					`hide_after` DATE NULL DEFAULT NULL,
					`updated_by` INT NULL DEFAULT NULL,
					`updated_at` DATETIME NULL DEFAULT NULL,
					PRIMARY KEY (`pluslet_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
				"CREATE TABLE IF NOT EXISTS `guide_review` (
					`subject_id` BIGINT NOT NULL,
					`interval_months` INT NULL DEFAULT NULL,
					`last_reviewed` DATE NULL DEFAULT NULL,
					`reviewed_by` INT NULL DEFAULT NULL,
					`last_reminded` DATE NULL DEFAULT NULL,
					PRIMARY KEY (`subject_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8",
			),
		),
	);
	return $migrations;
}

/** Opens a PDO connection from the values in config.php (exceptions on). */
function sp_migrate_connect( $hname, $uname, $pword, $dbName_SPlus, $db_port = '', $db_cert_path = '' ) {
	$port = ( $db_port !== '' && $db_port !== null ) ? $db_port : '3306';
	$dsn  = 'mysql:dbname=' . $dbName_SPlus . ';host=' . $hname . ';port=' . $port . ';charset=utf8mb4';
	$opts = array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION );
	if ( ! empty( $db_cert_path ) ) {
		$opts[ PDO::MYSQL_ATTR_SSL_CA ] = $db_cert_path;
	}
	return new PDO( $dsn, $uname, $pword, $opts );
}

/** id => applied_at (or null when pending), in order. */
function sp_migrate_status( PDO $pdo ) {
	$pdo->exec( "CREATE TABLE IF NOT EXISTS `jgu_schema_migration` (
		`id` VARCHAR(191) NOT NULL PRIMARY KEY,
		`description` VARCHAR(255) NOT NULL,
		`applied_at` DATETIME NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8" );
	$applied = $pdo->query( 'SELECT id, applied_at FROM jgu_schema_migration' )->fetchAll( PDO::FETCH_KEY_PAIR );
	$out = array();
	foreach ( sp_migrations() as $id => $m ) {
		$out[ $id ] = array( 'description' => $m[0], 'applied_at' => isset( $applied[ $id ] ) ? $applied[ $id ] : null );
	}
	return $out;
}

/** Number of migrations not yet applied (0 if the check itself fails). */
function sp_migrate_pending_count( PDO $pdo ) {
	try {
		$n = 0;
		foreach ( sp_migrate_status( $pdo ) as $s ) {
			if ( $s['applied_at'] === null ) {
				$n++;
			}
		}
		return $n;
	} catch ( Exception $e ) {
		return 0;
	}
}

/**
 * Applies pending migrations. $log receives progress lines. Returns the number applied.
 * Throws on the first real error (after logging it); earlier migrations stay applied.
 */
function sp_migrate_apply( PDO $pdo, $log ) {
	// "already done" errors: duplicate column / key / table / foreign key / unknown key to drop
	$already = array( 1050, 1060, 1061, 1826, 1091 );
	$count   = 0;
	$status  = sp_migrate_status( $pdo );
	foreach ( sp_migrations() as $id => $m ) {
		if ( $status[ $id ]['applied_at'] !== null ) {
			continue;
		}
		call_user_func( $log, "Applying $id: {$m[0]} ..." );
		// MySQL DDL auto-commits, so each statement is applied individually; the migration
		// is only recorded once all of its statements have succeeded.
		foreach ( $m[1] as $sql ) {
			try {
				$pdo->exec( $sql );
			} catch ( PDOException $e ) {
				$code = isset( $e->errorInfo[1] ) ? (int) $e->errorInfo[1] : 0;
				if ( in_array( $code, $already, true ) ) {
					call_user_func( $log, "  (already in place, skipped: " . $e->errorInfo[2] . ")" );
					continue;
				}
				call_user_func( $log, "  FAILED: " . $e->getMessage() );
				throw $e;
			}
		}
		$stmt = $pdo->prepare( 'INSERT INTO jgu_schema_migration (id, description, applied_at) VALUES (?, ?, NOW())' );
		$stmt->execute( array( $id, $m[0] ) );
		call_user_func( $log, "  done" );
		$count++;
	}
	return $count;
}
