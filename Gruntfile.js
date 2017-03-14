/* jshint node:true */
/* global require */

module.exports = function (grunt) {
    'use strict';

    var path = require('path');

    var loader = require('load-project-config'),
        config = require('grunt-plugin-fleet');

    loader(grunt, config).init();
};