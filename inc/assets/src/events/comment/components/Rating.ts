import $, { JQuery } from 'jquery'

export type TRatingProps = {
	value: number
	disabled: boolean
	name: string
}

export class Rating {
	$element: JQuery<HTMLElement>
	props: TRatingProps

	constructor(element, props) {
		this.$element = $(element)
		this.props = props
		this.render()
	}

	render() {
		const disabledAttr = this.props.disabled ? 'disabled' : ''

		const inputHTML = Array.from({ length: 5 }, (_, index) => {
			const checked = index + 1 === this.props.value
			return `<input type="radio" value="${index + 1}" name="${this.props.name}" class="bg-yellow-400 pc-mask pc-mask-star" ${disabledAttr} ${checked ? 'checked="checked"' : ''} />`
		}).join('\n')

		this.$element.html(/*html*/ `
			<div class="pc-rating pc-rating-sm">${inputHTML}</div>
		`)
	}
}
