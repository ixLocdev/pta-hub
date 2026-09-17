<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-simple-mode.php';

$t = 'PTK_Simple_Mode';

// The meta and option names.
ptk_test_ok( 'ptk_simple_mode' === $t::USER_META, 'user meta key is ptk_simple_mode' );
ptk_test_ok( 'ptk_simple_mode_roles' === $t::ROLES_OPTION, 'site option is ptk_simple_mode_roles' );

// Everyone but administrators, by default.
ptk_test_ok( array( 'editor', 'author', 'contributor', 'subscriber' ) === $t::default_roles(), 'default_roles() is everyone but administrator' );
ptk_test_ok( ! in_array( 'administrator', $t::default_roles(), true ), 'administrator is never a default-on role' );

// on_for(): the person's own choice wins.
ptk_test_ok( false === $t::on_for( '0', array( 'subscriber' ), $t::default_roles() ), 'an explicit 0 beats a default-on role' );
ptk_test_ok( true === $t::on_for( '1', array( 'administrator' ), $t::default_roles() ), 'an explicit 1 beats a default-off role' );

// on_for(): absent meta falls back to the role default.
ptk_test_ok( false === $t::on_for( '', array( 'administrator' ), $t::default_roles() ), 'an admin with no meta is off' );
ptk_test_ok( true === $t::on_for( '', array( 'subscriber' ), $t::default_roles() ), 'a volunteer with no meta is on' );
ptk_test_ok( false === $t::on_for( '', array( 'some_unknown_role' ), $t::default_roles() ), 'an unknown role is ignored, so it is off' );
ptk_test_ok( true === $t::on_for( '', array( 'some_unknown_role', 'editor' ), $t::default_roles() ), 'one known default-on role among unknown ones is still on' );
ptk_test_ok( false === $t::on_for( '', array(), $t::default_roles() ), 'no roles at all is off' );

// A site can choose its own default list instead of the built-in one.
ptk_test_ok( true === $t::on_for( '', array( 'administrator' ), array( 'administrator' ) ), 'a site-chosen default list can include administrator' );

// sanitize_roles(): only real role slugs survive.
$all_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
ptk_test_ok( array( 'editor', 'author' ) === $t::sanitize_roles( array( 'editor', 'author' ), $all_roles ), 'real roles pass through' );
ptk_test_ok( array( 'editor' ) === $t::sanitize_roles( array( 'editor', 'made-up-role' ), $all_roles ), 'a made-up role is dropped' );
ptk_test_ok( array( 'administrator' ) === $t::sanitize_roles( array( 'administrator' ), $all_roles ), 'administrator may be included if a site chooses' );
ptk_test_ok( array() === $t::sanitize_roles( array(), $all_roles ), 'nothing submitted keeps nothing' );
ptk_test_ok( array() === $t::sanitize_roles( 'not-an-array', $all_roles ), 'a non-array submission is treated as nothing' );
ptk_test_ok( array( 'editor' ) === $t::sanitize_roles( array( 'editor', 'editor' ), $all_roles ), 'a duplicate does not appear twice' );

// too_small_for_hub(): people who lack edit_posts never land in the admin.
ptk_test_ok( true === $t::too_small_for_hub( array() ), 'no caps at all is too small for the Hub' );
ptk_test_ok( true === $t::too_small_for_hub( array( 'read' => true ) ), 'read-only (a plain subscriber) is too small for the Hub' );
ptk_test_ok( true === $t::too_small_for_hub( array( 'edit_posts' => false ) ), 'edit_posts explicitly false is too small for the Hub' );
ptk_test_ok( false === $t::too_small_for_hub( array( 'edit_posts' => true ) ), 'edit_posts true is not too small for the Hub' );

// active(): nothing is on when the new look is off, whatever else is true.
ptk_test_ok( false === $t::active( false, '1', array( 'administrator' ), array( 'administrator' ) ), 'the new look off beats an explicit 1' );
ptk_test_ok( false === $t::active( false, '', array( 'subscriber' ), $t::default_roles() ), 'the new look off beats a default-on role' );
ptk_test_ok( true === $t::active( true, '1', array(), array() ), 'the new look on plus an explicit 1 is on' );
ptk_test_ok( true === $t::active( true, '', array( 'subscriber' ), $t::default_roles() ), 'the new look on plus a default-on role is on' );
ptk_test_ok( false === $t::active( true, '', array( 'administrator' ), $t::default_roles() ), 'the new look on but an admin with no meta is off' );

// keep_menu_slug(): a plain allow-list check, testable without WordPress.
ptk_test_ok( true === $t::keep_menu_slug( 'profile.php', array( 'edit.php?post_type=pta_knowledge', 'profile.php' ) ), 'profile.php survives when it is in the keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'plugins.php', array( 'edit.php?post_type=pta_knowledge', 'profile.php' ) ), 'plugins.php does not survive when it is not in the keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'anything', array() ), 'nothing survives an empty keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'anything', null ), 'a non-array keep list is treated as empty' );

// The top-level keep list: the Hub's own menu, plus Profile, nothing else.
ptk_test_ok( array( 'edit.php?post_type=pta_knowledge', 'profile.php' ) === $t::top_level_keep_slugs(), 'top level keeps only the Hub menu and Profile' );

// The Hub-task submenu keep list: task screens, not once-in-a-while admin screens.
$hub_tasks = $t::hub_task_submenu_slugs();
foreach ( array( 'ptk-welcome', 'ptk-newsletter-builder', 'ptk-content-wizard', 'edit.php?post_type=pta_knowledge', 'edit.php?post_type=pta_newsletter' ) as $task_slug ) {
    ptk_test_ok( in_array( $task_slug, $hub_tasks, true ), "hub_task_submenu_slugs() keeps $task_slug" );
}
foreach ( array( 'ptk-settings', 'ptk-search-analytics', 'ptk-content-importer', 'ptk-network-sync', 'ptk-school-colors', 'ptk-vendor-approvals', 'ptk-share-settings' ) as $admin_slug ) {
    ptk_test_ok( ! in_array( $admin_slug, $hub_tasks, true ), "hub_task_submenu_slugs() drops the once-in-a-while admin screen $admin_slug" );
}

// The admin-bar keep list.
$bar_keep = $t::admin_bar_keep_ids();
foreach ( array( 'site-name', 'my-sites', 'my-account', 'ptk-simple-mode' ) as $bar_id ) {
    ptk_test_ok( in_array( $bar_id, $bar_keep, true ), "admin_bar_keep_ids() keeps $bar_id" );
}
foreach ( array( 'new-content', 'comments', 'updates', 'wp-logo' ) as $bar_id ) {
    ptk_test_ok( ! in_array( $bar_id, $bar_keep, true ), "admin_bar_keep_ids() drops $bar_id" );
}

ptk_test_done();
