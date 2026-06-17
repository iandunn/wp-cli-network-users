- [ ] add --sites param to delete uesers in similar way as it works for set-role
	b/c might want to delete from all sites but leave network account -- that's how scope=sites works
	but might want to delte from some sites, or current sites - current sites is covered by scope=sites
	so really the only addition here would be to delete them from a specific set of sites while leaving on others
	could maybe reuse --scope command, and maybe alias that to --sites for consistency

- [ ] add a `user list-network` command to show which sites a user is on and the role they have.
	wp-admin/users.php already shows the sites, but not the roles
	`user list` shows the role on the current site but not network
	takes a single user as first param, no flags
	2 column table output w/ site and role headers
	include an info message at the top that shows last login
	could show other info too
	well them maybe this should actually be `user get-network`

	maybe also show table of users w/ last login just like would when doing dry-run
	that would have to be based on passing different flags b/c it'd show multiple users instead of one, or maybe this should be a separate command
	so maybe this is `list-network` while above is `get-network`

- [ ]  get_sites( [ 'number' => 10000 ] ) (line 277): Silently truncates networks
   with >10k sites — missed sites get no content reassignment. Use 'number' => -1 or paginate.
   or show warning to user if network has more than 10k sites. that might be better
   eh, probably switch to doing -1 by default. but still show warning that it might be slow if > 10k sites
