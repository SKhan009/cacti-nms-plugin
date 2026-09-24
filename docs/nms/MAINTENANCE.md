# NMS maintenance rules

The NMS plugin owns its PHP and JavaScript source outside dependency `vendor/` directories.
Every named function and method in that owned source must have a short
documentation block directly above it. The block should explain the operation
and should be updated whenever the function's responsibility changes.

Do not edit `ssh/vendor/` or `vendor/leaflet/`. Third-party code should be upgraded as a
complete dependency instead of being reformatted or annotated locally.

Before release, run PHP syntax validation in the target RHEL environment:

```sh
find plugins/nms -path '*/vendor/*' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```
