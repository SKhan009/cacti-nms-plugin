# Rack Topology Test Checklist

- Plugin appears in Cacti Plugin Management.
- Install and Enable complete without SQL errors.
- View and Manage realms appear in user permissions.
- Create a 42U rack.
- Edit rack metadata.
- Add at least three normal Cacti devices.
- Place 1U and multi-U devices.
- Verify overlapping placement is rejected.
- Verify placement beyond top U is rejected.
- Verify one Cacti host cannot be placed twice.
- Verify Front and Rear displays are independent.
- Verify Cacti Up/Down/Disabled status changes appear after refresh.
- Verify device details panel and Cacti cross-launch.
- Verify drag/drop works for Manage users and is unavailable for View-only users.
- Verify View-only user cannot open administration page.
- Verify rack/placement changes appear in Rack Audit.
- Disable plugin and re-enable it; data should remain.
- Copy plugin folder to the second OS and confirm it installs without path edits.
