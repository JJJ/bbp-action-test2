<?php get_header(); ?>

<?php login_form(); ?>

<h2>Hot Tags <small>(<a href="#latest">skip to latest topics</a>)</small></h2>

<p class="frontpageheatmap"><?php tag_heat_map(); ?></p>

<h2>Views</h2>
<ul id="views">
<?php foreach ( get_views() as $view => $title ) : ?>
<li class="view"><a href="<?php echo get_view_link($view); ?>"><?php echo $view; ?></a></li>
<?php endforeach; ?>
</ul>

<?php if ( $topics ) : ?>

<h2>Latest Discussions</h2>

<table id="latest">
<tr>
	<th>Topic</th>
	<th>Posts</th>
	<th>Last Poster</th>
	<th>Freshness</th>
</tr>


<?php foreach ( $topics as $topic ) : ?>
<tr<?php alt_class('topic'); ?>>
	<td><a href="<?php topic_link(); ?>"><?php topic_title(); ?></a></td>
	<td class="num"><?php topic_posts(); ?></td>
	<td class="num"><?php topic_last_poster(); ?></td>
	<td class="num"><small><?php topic_time(); ?></small></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ( $forums ) : ?>
<h2>Forums</h2>
<table id="forumlist">

<tr>
	<th>Main Theme</th>
	<th>Topics</th>
	<th>Posts</th>
</tr>

<?php foreach ( $forums as $forum ) : ?>
<tr<?php alt_class('forum'); ?>>
	<td><a href="<?php forum_link(); ?>"><?php forum_name(); ?></a> &#8212; <small><?php forum_description(); ?></small></td>
	<td class="num"><?php forum_topics(); ?></td>
	<td class="num"><?php forum_posts(); ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php get_footer(); ?>
