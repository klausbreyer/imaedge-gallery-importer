REMOTE_USER := axaxxc
REMOTE_HOST := www632.your-server.de
REMOTE_PORT := 222
REMOTE_PATH := /usr/www/users/axaxxc/blog.breyer.berlin/wp-content/plugins/imaedge-gallery-importer
REMOTE := $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/
SSH := ssh -p $(REMOTE_PORT)
RSYNC := rsync
RSYNC_FLAGS := -az --progress --delete
RSYNC_EXCLUDES := \
	--exclude=.git/ \
	--exclude=.DS_Store \
	--exclude=Makefile

.PHONY: deploy deploy-dry-run

deploy:
	$(SSH) $(REMOTE_USER)@$(REMOTE_HOST) 'mkdir -p "$(REMOTE_PATH)"'
	$(RSYNC) $(RSYNC_FLAGS) $(RSYNC_EXCLUDES) -e 'ssh -p $(REMOTE_PORT)' ./ $(REMOTE)

deploy-dry-run:
	$(SSH) $(REMOTE_USER)@$(REMOTE_HOST) 'mkdir -p "$(REMOTE_PATH)"'
	$(RSYNC) $(RSYNC_FLAGS) --dry-run $(RSYNC_EXCLUDES) -e 'ssh -p $(REMOTE_PORT)' ./ $(REMOTE)
