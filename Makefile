COMPOSER = docker run --rm -v "$(PWD)":/opt -w /opt laravelsail/php82-composer:latest

composer:
	$(COMPOSER) composer $(filter-out $@,$(MAKECMDGOALS))

%:
	@:
