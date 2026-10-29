const path = require('path');
const fs = require('fs');

let gulp,
	gulpSass,
	sass;

// Use the packages of this folder, or those of the theme when one is missing here
const themeNodeModules = path.resolve(__dirname, '../../themes/straps/assets/node_modules');

function resolveModule(name) {
	try {
		return require(name);
	} catch (e) {
		return require(path.join(themeNodeModules, name));
	}
}

gulp = resolveModule('gulp');
gulpSass = resolveModule('gulp-sass');
sass = gulpSass(resolveModule('sass'));

gulp.task('css', function() {
	return gulp.src('scss/**/*.scss').pipe(sass({ outputStyle: 'expanded' }).on('error', sass.logError)).pipe(gulp.dest('css'));
});

gulp.task('watch', function() {
	gulp.watch('scss/**/*.scss', gulp.series('css'));
});

gulp.task('default', gulp.series('css'));
