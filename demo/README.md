# WordPress demo site

Docker image of a WordPress + WPML site with this plugin, for a demo on Railway.
Variables, setup steps and limits: see [docs/DEVELOPER.md → Demo site](../docs/DEVELOPER.md#demo-site).

```sh
docker build -f demo/Dockerfile -t wordpress-wpml-supertext-demo .
docker run -p 8080:8080 -e DATABASE_URL=mysql://root:secret@host:3306/wordpress \
  -e DEMO_ADMIN_EMAIL=admin@example.com -e DEMO_ADMIN_PASSWORD=change-me \
  -e WPML_ZIP_URL=https://… -e DEMO_SITE_URL=http://localhost:8080 wordpress-wpml-supertext-demo
```
