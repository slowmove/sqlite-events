COMPOSER ?= composer

cs-fixer: ## Run PHP CS Fixer
	$(COMPOSER) run php:cs:fixer

phpstan: ## Run Phpstan
	$(COMPOSER) run phpstan

help: ## Show this help
	@echo "Available targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
