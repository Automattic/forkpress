# Resolve fastlane gem deps and install the Automattic Developer ID
# Application cert into a temp keychain via the `set_up_signing` lane.
#
# Usage:
#   source .buildkite/commands/_lib/setup-fastlane.sh

echo "--- :fastlane: bundle install + cert install"
bundle install
bundle exec fastlane set_up_signing
