.SILENT:
.PHONY: help up down build restart shell plugin-list test test-coverage cs cs-fix analyse fixture-load resync prepare validate-plugin cli changelog zip

CONTAINER := shopware
PLUGIN_DIR := custom/static-plugins/KmhFlutterwaveSW

# Plugins installed via composer
STATIC_PLUGINS := \
	kommandhub/flutterwave-sw:KmhFlutterwaveSW

# Only plugins that should be copied into custom/static-plugins
STATIC_COPY_PLUGINS := \

# This plugin may ship at any stability (see "version" in composer.json), while
# the Shopware install it is tested against pins minimum-stability to "stable".
# Composer therefore refuses to resolve a pre-release unless the requirement
# carries its own stability flag:
#
#   Could not find a version of package kommandhub/flutterwave-sw matching your
#   minimum-stability (stable).
#
# Composer's ladder is: dev < alpha < beta < RC < stable. A flag accepts its own
# level *and everything above it*, so "@dev" — the bottom rung — accepts every
# stability there is. That makes this work unchanged for 0.9.0-alpha.1,
# -beta.1, -RC1, a dev- branch, and 1.0.0 stable, with no edit per release.
# ("@beta" would have covered beta/RC/stable but silently broken on an alpha.)
#
# This is a PER-PACKAGE flag: the root minimum-stability stays "stable", so no
# other dependency can quietly resolve to a pre-release. That containment is why
# this is preferred over relaxing minimum-stability globally.
#
# It is only permissive about *stability*, not about which package is chosen:
# this plugin resolves from the custom/static-plugins path repository, which
# offers exactly one candidate — the working tree being tested.
PLUGIN_PACKAGE := kommandhub/flutterwave-sw
PLUGIN_STABILITY := *@dev

# Composer install list (includes all). The package under development is
# requested with its stability flag; every other plugin is required as-is.
COMPOSER_PLUGINS := $(patsubst $(PLUGIN_PACKAGE),'$(PLUGIN_PACKAGE):$(PLUGIN_STABILITY)',\
	$(foreach p,$(STATIC_PLUGINS),$(word 1,$(subst :, ,$(p)))))

define CHECK_READY
@if [ -z "$$(docker compose ps $(CONTAINER) --status running --quiet)" ]; then \
	echo "Error: Container '$(CONTAINER)' is not running. Please run 'make up' first."; \
	exit 1; \
fi; \
HEALTH=$$(docker compose ps $(CONTAINER) --format '{{.Health}}'); \
if [ "$$HEALTH" != "healthy" ] && [ -n "$$HEALTH" ]; then \
	echo "Waiting for container '$(CONTAINER)' to be healthy..."; \
	while [ "$$(docker compose ps $(CONTAINER) --format '{{.Health}}')" != "healthy" ]; do \
		printf "."; \
		sleep 1; \
	done; \
	echo " Ready!"; \
fi
endef

define EXEC
docker compose exec $(CONTAINER) bash -c "$(1)"
endef

define EXEC_IN_PLUGIN
$(call EXEC,cd $(PLUGIN_DIR) && $(1))
endef

help:
	@echo "Available commands:"
	@echo "  up                - Start the shopware container"
	@echo "  down              - Stop the shopware container"
	@echo "  build             - Rebuild the shopware container"
	@echo "  restart           - Restart the environment"
	@echo "  shell             - Open a shell session in the shopware container"
	@echo "  plugin-list       - List all plugins"
	@echo "  test              - Run phpunit tests"
	@echo "  test-coverage     - Run phpunit tests with coverage report"
	@echo "  cs                - Run php-cs-fixer checks"
	@echo "  cs-fix            - Run php-cs-fixer fix"
	@echo "  analyse           - Run phpstan analysis"
	@echo "  fixture-load      - Load fixtures"
	@echo "  resync            - Sync config directory into the root project"
	@echo "  prepare           - Full project preparation"
	@echo "  validate-plugin   - Validate the plugin with shopware-cli (store compliance)"
	@echo "  cli               - Run any shopware-cli command: make cli ARGS=\"--version\""
	@echo "  changelog         - Render the plugin changelog as the store would"
	@echo "  zip               - Build a distributable plugin zip into build/"

up:
	docker compose up -d --build
	$(MAKE) prepare

down:
	docker compose down -v

build:
	docker compose build

restart: down up

shell:
	$(CHECK_READY)
	docker compose exec $(CONTAINER) bash

plugin-list:
	$(CHECK_READY)
	docker compose exec $(CONTAINER) bin/console plugin:list

test:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,php ../../../bin/phpunit -c phpunit.dist.xml --testdox --display-deprecations \
                                                                                   --display-warnings \
                                                                                   --display-notices --color=always $${FILTER})

test-coverage:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,php ../../../bin/phpunit -c phpunit.dist.xml --coverage-text --display-deprecations \
																						  --display-warnings \
																						  --display-notices --color=always)

cs:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,./vendor/bin/php-cs-fixer fix --dry-run --diff)

cs-fix:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,./vendor/bin/php-cs-fixer fix)

analyse:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,./vendor/bin/phpstan analyse src -c phpstan.dist.neon --memory-limit=1G)

# shopware-cli lives in the image (see Dockerfile), so these run against the same
# PHP version and vendor tree as the tests — not whatever a developer has on their
# host. Run `make build` after pulling a change to the Dockerfile.

validate-plugin:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,shopware-cli extension validate . --full --store-compliance --no-copy)

# Escape hatch for the rest of the CLI, so a new target is not needed per command:
#   make cli ARGS="extension get-version ."
#   make cli ARGS="extension format ."
cli:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,shopware-cli $(ARGS))

changelog:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,shopware-cli extension get-changelog .)

# --disable-git packages the working tree as it stands. Without it the CLI zips
# from a git ref, which fails in this mounted checkout ("cannot find checkout tag
# or branch") and would exclude uncommitted work anyway — the opposite of what a
# test-container package is for.
zip:
	$(CHECK_READY)
	$(call EXEC_IN_PLUGIN,shopware-cli extension zip . --release --disable-git --output-directory build)

fixture-load:
	$(CHECK_READY)
	docker compose exec $(CONTAINER) bin/console fixture:load --no-interaction

resync:
	@echo "Resyncing config directory..."
	$(CHECK_READY)
	docker compose exec $(CONTAINER) cp -R $(PLUGIN_DIR)/tests/Setup/config/. config/

prepare:
	@echo ""
	@echo "Preparing the project..."
	$(CHECK_READY)
	
	docker compose exec $(CONTAINER) rm -rf custom/plugins/*

# Drop the copies a previous run left in custom/static-plugins, and the vendor
# entries pointing at them, BEFORE composer resolves anything.
#
# The root composer.json registers custom/static-plugins/* as a path repository,
# so a copy left there is a package in its own right and takes precedence over
# Packagist. That makes prepare work exactly once per fresh container and fail on
# every later run: composer symlinks vendor/<pkg> -> custom/static-plugins/<Plugin>,
# and the copy step below then tries to copy a directory onto itself
# ("are the same file").
#
# It also silently pins the version — once copied, "^1.0" resolves to whatever is
# on disk rather than the latest release. Clearing first keeps Packagist
# authoritative and makes this target idempotent.
	@echo "Clearing previously copied vendor plugins..."
	@$(foreach plugin,$(STATIC_COPY_PLUGINS), \
		VENDOR_DIR=$(word 1,$(subst :, ,$(plugin))); \
		TARGET_DIR=$(word 2,$(subst :, ,$(plugin))); \
		echo "  $$TARGET_DIR"; \
		docker compose exec $(CONTAINER) rm -rf custom/static-plugins/$$TARGET_DIR vendor/$$VENDOR_DIR; \
	)

	@echo "Installing required plugins via composer..."
	docker compose exec $(CONTAINER) composer require $(COMPOSER_PLUGINS) --no-interaction

	@echo "Copying vendor plugins to static-plugins (excluding main plugin)..."
	@$(foreach plugin,$(STATIC_COPY_PLUGINS), \
		VENDOR_DIR=$(word 1,$(subst :, ,$(plugin))); \
		TARGET_DIR=$(word 2,$(subst :, ,$(plugin))); \
		echo "  $$VENDOR_DIR -> $$TARGET_DIR"; \
		docker compose exec $(CONTAINER) mkdir -p custom/static-plugins/$$TARGET_DIR; \
		docker compose exec $(CONTAINER) cp -R vendor/$$VENDOR_DIR/. custom/static-plugins/$$TARGET_DIR/; \
	)

	@echo "Installing plugin dependencies..."
	$(call EXEC_IN_PLUGIN,composer install --no-interaction)

	# $(MAKE) resync
