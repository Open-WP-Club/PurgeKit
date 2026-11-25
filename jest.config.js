// wp-scripts 37 no longer ships Jest defaults, so the suite brings its own.
module.exports = {
	testEnvironment: 'jsdom',
	setupFiles: [ '<rootDir>/assets/src/__setup__/jest.setup.js' ],
	testMatch: [ '<rootDir>/assets/src/**/__tests__/**/*.js' ],
	transform: {
		'\\.[jt]sx?$': [ 'babel-jest', { babelrc: false, configFile: false, presets: [ '@wordpress/babel-preset-default' ] } ],
	},
};
