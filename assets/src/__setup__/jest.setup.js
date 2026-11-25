// What the old wp-scripts Jest defaults provided and the components expect from a browser.
if ( ! window.matchMedia ) {
	window.matchMedia = ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	} );
}
if ( ! window.ResizeObserver ) {
	window.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
}
if ( ! window.scrollTo ) {
	window.scrollTo = () => {};
}
