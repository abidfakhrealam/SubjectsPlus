<?php
namespace SubjectsPlus\Control\Install;

use PDO;

/**
 * Optional starter content for a new site, so the public pages and the editor show a working example:
 * a public subject guide, a hidden course-guide template, three open-access databases on the A–Z list
 * and an FAQ. Everything can be edited or deleted afterwards.
 */
class SampleContent {

	/** @return array log lines */
	public static function install( PDO $pdo, $staffId = 1 ) {
		$log = array();
		$pdo->beginTransaction();
		try {
			// ---- databases (A–Z list): open access, so they work without a proxy
			$sourceId = (int) $pdo->query( "SELECT source_id FROM source ORDER BY rs, source_id LIMIT 1" )->fetchColumn() ?: null;
			$formatId = (int) $pdo->query( "SELECT format_id FROM format ORDER BY format_id LIMIT 1" )->fetchColumn() ?: null;
			$openId   = (int) $pdo->query( "SELECT restrictions_id FROM restrictions ORDER BY restrictions_id LIMIT 1" )->fetchColumn() ?: 1;
			$status   = self::publicStatus( $pdo );
			$records  = array(
				array( 'Directory of Open Access Journals (DOAJ)', 'https://doaj.org/', 'Peer-reviewed open-access journals in all subjects.', 'full_text' ),
				array( 'PubMed', 'https://pubmed.ncbi.nlm.nih.gov/', 'Citations and abstracts for biomedical and life-sciences literature.', '' ),
				array( 'Google Scholar', 'https://scholar.google.com/', 'Search scholarly literature across many disciplines and sources.', '' ),
			);
			$titleIds = array();
			foreach ( $records as $r ) {
				$pdo->prepare( "INSERT INTO title (title, description, last_modified_by) VALUES (?, ?, 'installer')" )->execute( array( $r[0], $r[2] ) );
				$tid = (int) $pdo->lastInsertId();
				$pdo->prepare( "INSERT INTO location (format, call_number, location, access_restrictions, eres_display, display_note, ctags, record_status) VALUES (?, '', ?, ?, 'Y', '', ?, ?)" )
				    ->execute( array( $formatId, $r[1], $openId, $r[3], $status ) );
				$lid = (int) $pdo->lastInsertId();
				$pdo->prepare( "INSERT INTO location_title (location_id, title_id) VALUES (?, ?)" )->execute( array( $lid, $tid ) );
				$titleIds[] = $tid;
			}
			$log[] = count( $records ) . ' sample databases added to the A–Z list.';

			// ---- a public subject guide
			$guideId = self::guide( $pdo, 'Getting Started with Research', 'getting-started', 'Subject', 1, '',
				'Finding, evaluating and citing sources: a starting point for any assignment.', $staffId,
				array(
					array( 'Start here', 'Basic', '<p>This sample guide was created by the installer. Open it in the guide editor (<em>Guides → My Guides</em>) to see how tabs, sections and boxes work, then edit or delete it.</p><ol><li>Pick a topic and list its key words.</li><li>Search the databases on this page.</li><li>Evaluate what you find, then cite it.</li></ol>' ),
					array( 'Ask a librarian', 'Basic', '<p>Can\'t find what you need? Contact the library: replace this text with your help-desk email, chat link and opening hours.</p>' ),
					array( 'Evaluating sources', 'Basic', '<p>Ask of every source: <strong>who</strong> wrote it, <strong>when</strong>, <strong>why</strong>, and <strong>what evidence</strong> it gives.</p>' ),
				) );
			foreach ( $titleIds as $i => $tid ) {
				$pdo->prepare( "INSERT INTO `rank` (`rank`, subject_id, title_id, source_id, description_override, dbbysub_active) VALUES (?, ?, ?, ?, '', 1)" )
				    ->execute( array( $i, $guideId, $tid, $sourceId ) );
			}
			$log[] = 'Sample public guide “Getting Started with Research” (getting-started).';

			// ---- a hidden course-guide template
			self::guide( $pdo, 'Course Guide Template', 'course-template', 'Course', 0, json_encode( array( 'template' => 1 ) ),
				'Template: readings, databases, assignment help.', $staffId,
				array(
					array( 'Course readings', 'Basic', '<p>List the required and recommended readings here.</p>' ),
					array( 'Key databases', 'Basic', '<p>Link the two or three databases students should start with.</p>' ),
					array( 'Assignment help', 'Basic', '<p>Tips for this course\'s assignments and citation style.</p>' ),
				) );
			$log[] = 'Hidden template “Course Guide Template” (offered under Guides → Start from a template).';

			// ---- an FAQ
			$pdo->prepare( "INSERT INTO faq (question, answer, keywords) VALUES (?, ?, ?)" )->execute( array(
				'How do I access e-resources from off campus?',
				'<p>Sign in through the library\'s off-campus access link on each database. Replace this answer with your own instructions.</p>',
				'off campus, remote access, proxy' ) );
			$faqId = (int) $pdo->lastInsertId();
			$pdo->prepare( "INSERT INTO faq_subject (faq_id, subject_id) VALUES (?, ?)" )->execute( array( $faqId, $guideId ) );
			$log[] = 'Sample FAQ added.';

			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw new \RuntimeException( 'Sample content could not be added: ' . Database::shortError( $e ) );
		}

		return $log;
	}

	/** Creates subject → tab → section → boxes; returns subject_id. */
	private static function guide( PDO $pdo, $name, $short, $type, $active, $extra, $desc, $staffId, array $boxes ) {
		$pdo->prepare( "INSERT INTO subject (subject, active, shortform, redirect_url, description, keywords, type, extra) VALUES (?, ?, ?, '', ?, '', ?, ?)" )
		    ->execute( array( $name, $active, $short, $desc, $type, $extra !== '' ? $extra : '{"maincol":""}' ) );
		$sid = (int) $pdo->lastInsertId();
		$pdo->prepare( "INSERT INTO staff_subject (staff_id, subject_id, staff_guide_order) VALUES (?, ?, 0)" )->execute( array( $staffId, $sid ) );
		$pdo->prepare( "INSERT INTO tab (subject_id, label, tab_index, visibility) VALUES (?, 'Main', 0, 1)" )->execute( array( $sid ) );
		$tab = (int) $pdo->lastInsertId();
		$pdo->prepare( "INSERT INTO section (section_index, layout, tab_id) VALUES (0, '0-8-4', ?)" )->execute( array( $tab ) );
		$sec = (int) $pdo->lastInsertId();
		foreach ( $boxes as $i => $b ) {
			$pdo->prepare( "INSERT INTO pluslet (title, body, type, extra) VALUES (?, ?, ?, '')" )->execute( array( $b[0], $b[2], $b[1] ) );
			$pid = (int) $pdo->lastInsertId();
			// first box in the main column, the others in the sidebar
			$pdo->prepare( "INSERT INTO pluslet_section (pluslet_id, section_id, pcolumn, prow) VALUES (?, ?, ?, ?)" )
			    ->execute( array( $pid, $sec, $i === 0 ? 1 : 2, $i === 0 ? 0 : $i - 1 ) );
		}

		return $sid;
	}

	/** A record status that is shown to the public (record settings), or '' for the legacy default. */
	private static function publicStatus( PDO $pdo ) {
		try {
			$s = $pdo->query( "SELECT name FROM record_status_option WHERE is_public = 1 AND active = 1 ORDER BY (stage = 'active') DESC, sort_order LIMIT 1" );
			$n = $s ? $s->fetchColumn() : false;

			return $n !== false ? (string) $n : 'Active';
		} catch ( \Throwable $e ) {
			return 'Active';
		}
	}
}
