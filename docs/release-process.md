# Release Process

1. Confirm the version is synchronized:
   ```bash
   bash bin/verify-version.sh
   ```

2. Build the distribution zip from the release commit:
   ```bash
   bin/build-zip.sh build
   ```

3. Generate GitHub release checksums:
   ```bash
   cd build
   sha256sum *.zip > SHA256SUMS
   ```

4. Attach the same zip and `SHA256SUMS` to the GitHub release that correspond
   to the WordPress.org SVN release commit/tag.
