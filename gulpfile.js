var gulp = require('gulp'),
	browserify = require('browserify'),
	babelify = require('babelify'),
	source = require('vinyl-source-stream'),
	sass = require('gulp-sass'),
	gulpif = require('gulp-if'),
	uglify = require('gulp-uglify'),
	buffer = require('vinyl-buffer'),
	watchify = require('watchify'),
	packageJSON = require('./package.json'),
	semver = require('semver'),
	notify = require('gulp-notify'),
	sourcemaps = require('gulp-sourcemaps');

var PATHS = {
	JAVASCRIPT_DIST: './javascript/dist',
	JAVASCRIPT_SRC: './javascript/src',
	SCSS: './javascript/src',
	IMAGES: './javascript/src/img/**'
};

var isDev = typeof process.env.npm_config_development !== 'undefined';

var nodeVersionIsValid = semver.satisfies(process.versions.node, packageJSON.engines.node);

var browserifyOptions = {
	entries: './javascript/src/boot/index.js',
	paths: [PATHS.JAVASCRIPT_SRC]
};

if (!nodeVersionIsValid) {
	console.error('Invalid Node.js version. You need to be using ' + packageJSON.engines.node);
	process.exit();
}

// Default Node environment to production.
process.env.NODE_ENV = isDev ? 'development' : 'production';

if (isDev) {
	browserifyOptions.debug = true;
	browserifyOptions.plugin = [watchify];
}

gulp.task('js', function bundleJavaScript() {
	return browserify(browserifyOptions)
		.transform(babelify)
		.external('components/text-field/index')
		.external('deep-freeze')
		.external('react')
		.external('jQuery')
		.external('i18n')
		.external('silverstripe-component')
		.external('react-dom')
		.external('react-addons-test-utils')
		.external('react-redux')
		.external('redux')
		.external('redux-thunk')
		.external('page.js')
		.external('react-addons-css-transition-group')
		.bundle()
		.on('update', bundleJavaScript)
		.on('error', notify.onError({ message: 'Error: <%= error.message %>' }))
		.pipe(source('bundle.js'))
		.pipe(buffer())
		.pipe(sourcemaps.init({ loadMaps: true }))
		.pipe(gulpif(!isDev, uglify()))
		.pipe(sourcemaps.write('./'))
		.pipe(gulp.dest(PATHS.JAVASCRIPT_DIST));
});

gulp.task('sass', function () {
	return gulp.src('./javascript/src/styles/main.scss')
		.pipe(sass().on('error', notify.onError({ message: 'Error: <%= error.message %>' })))
		.pipe(gulp.dest(PATHS.JAVASCRIPT_DIST));
});

gulp.task('images', function () {
	return gulp.src(PATHS.IMAGES)
		.pipe(gulp.dest(PATHS.JAVASCRIPT_DIST + '/img'));
});

gulp.task('default', ['js', 'sass', 'images'], function () {
	if (isDev) {
		gulp.watch(PATHS.JAVASCRIPT_SRC + '/**/*.js', ['js']);
		gulp.watch(PATHS.SCSS + '/**/*.scss', ['sass']);
	}
});
