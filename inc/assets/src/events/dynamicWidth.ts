import $ from 'jquery'
import { store, windowWidthAtom } from '../store'
import { debounce } from 'lodash-es'

const adjustWidth = () => {
	store.set(windowWidthAtom, () => window.innerWidth)
}

export const dynamicWidth = () => {
	store.set(windowWidthAtom, () => window.innerWidth)
	$(window).on(
		'resize',
		debounce(() => adjustWidth(), 300),
	)
}
