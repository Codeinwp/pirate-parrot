//https://github.com/sindresorhus/grunt-shell

module.exports = {
    set_repo: {
        command: 'export THEMEISLE_REPO=<%= package.name %>'
    },
    set_version: {
        command: 'export THEMEISLE_VERSION=<%= package.version %>'
    }
};
