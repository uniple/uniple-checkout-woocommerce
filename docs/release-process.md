# Release Process

1. Start from a dedicated clean release clone. On the checkout host,
   `/home/ubuntu/uniple-checkout-woocommerce` is the GitHub-authenticated
   repository, but do not reset, clean, or reuse it when it contains merchant
   runtime work. Import the reviewed release branch into a separate clone and
   verify its base against `origin/main`.

2. Confirm the version is synchronized:
   ```bash
   bash bin/verify-version.sh
   ```

3. Run Composer validation, PHPUnit, the catalog controller contract, and the
   product auto-pull contract.

4. Build the distribution ZIP at least twice from the release commit, including
   a clean clone with a different umask:
   ```bash
   bin/build-zip.sh build-1
   bin/build-zip.sh build-2
   sha256sum build-1/*.zip build-2/*.zip
   ```
   The size and SHA-256 must match. The builder uses PHP `ZipArchive` with
   normalized ordering, timestamps, modes, and stored entries.

5. In an isolated current WordPress + WooCommerce installation, run Plugin
   Check 2.0 in update mode, both standard and experimental. Review every
   warning and require zero errors. Install the public 0.1.10 release first,
   seed synthetic settings/product/order state, then update through the
   standard WordPress updater to the exact candidate ZIP and byte-compare the
   before/after state.

6. Generate GitHub release checksums:
   ```bash
   cd build
   sha256sum *.zip > SHA256SUMS
   ```

7. Promote that exact candidate through dev and Production gates before public
   publication. Do not publish a candidate whose runtime differs from the
   deployed/tested artifact.

8. From the separate checkout-host release clone, push the reviewed release
   branch and tag. Attach the same ZIP and `SHA256SUMS` to the GitHub release
   that correspond to the WordPress.org SVN `trunk` and `tags/<version>`
   contents.
