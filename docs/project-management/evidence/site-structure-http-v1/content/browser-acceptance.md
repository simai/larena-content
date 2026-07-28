# Browser acceptance boundary

The package transition proves the complete HTTP flow through Laravel's real
HTTP kernel in `Tests\\Feature\\SiteStructureHttpAcceptanceTest`:

- shared Admin shell and Simai Framework asset markers render;
- Editor draft and submit work;
- Reader reads and mutation is forbidden;
- Administrator publishes and restores;
- public navigation, robots, sitemap, canonical and robots headers work;
- historical Content locator returns one direct 301.

This is the package-level browser-compatible HTTP proof. A real disposable
browser session against the exact sealed Root release is the next transition
and is not pre-claimed here.

