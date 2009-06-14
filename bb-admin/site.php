<?php
require_once('admin.php');

$bb_admin_body_class = ' bb-admin-tools';

bb_get_admin_header();
?>

<div class="wrap">

<h2><?php _e('Recount') ?></h2>
<?php do_action( 'bb_admin_notices' ); ?>

<p><?php _e("The following checkboxes allow you to recalculate various numbers stored in the database. These numbers are used for things like counting the number of pages worth of posts a particular topic has.  You shouldn't need to do do any of this unless you're upgrading from one version to another or are seeing pagination oddities.") ?></p>

<form method="post" action="<?php bb_uri('bb-admin/bb-do-counts.php', null, BB_URI_CONTEXT_FORM_ACTION + BB_URI_CONTEXT_BB_ADMIN); ?>">
	<fieldset>
		<legend><?php _e('Choose items to recalculate') ?></legend>
		<ol>
		<?php bb_recount_list(); if ( $recount_list ) : $i = 100; foreach ( $recount_list as $item ) : ?>
			<li<?php alt_class('recount'); ?>><label for="<?php echo $item[0]; ?>"><input name="<?php echo $item[0]; ?>" id="<?php echo $item[0]; ?>" type="checkbox" value="1" tabindex="<?php echo $i++; ?>" /> <?php echo $item[1]; ?>.</label></li>
		<?php endforeach; endif; ?>
		</ol>
		<p class="submit alignleft"><input name="Submit" type="submit" value="<?php _e('Count!') ?>" tabindex="<?php echo $i++; ?>" /></p>
		<?php bb_nonce_field( 'do-counts' ); ?>
	</fieldset>
</form>

</div>

<?php bb_get_admin_footer(); ?>
