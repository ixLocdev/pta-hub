<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-look.php';

$t = 'PTK_Hub_Look';

// The option name and its default: off, always, until a site opts in.
ptk_test_ok( 'ptk_hub_new_look' === $t::OPTION, 'option is ptk_hub_new_look' );
ptk_test_ok( false === $t::on_for( '' ), 'an unset option means off' );
ptk_test_ok( false === $t::on_for( '0' ), '"0" means off' );
ptk_test_ok( true === $t::on_for( '1' ), '"1" means on' );

// Which admin screens the look applies to. Hook suffixes, not guesses.
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-welcome', '' ), 'a Hub settings page is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-newsletter-builder', '' ), 'the Builder is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'edit.php', 'pta_newsletter' ), 'the newsletter list is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'post.php', 'pta_knowledge' ), 'a Hub entry editor is a Hub screen' );
ptk_test_ok( false === $t::is_hub_screen( 'edit.php', 'post' ), 'the ordinary posts list is not' );
ptk_test_ok( false === $t::is_hub_screen( 'plugins.php', '' ), 'core screens are not' );
ptk_test_ok( false === $t::is_hub_screen( 'toplevel_page_something-else', '' ), "another plugin's page is not" );

ptk_test_done();
