// @ts-ignore
import $, { JQuery } from 'jquery'

// 處理倒數計時器
export const countdown = () => {
	const Countdown = $('.pc-countdown-component')

	// each countdown component
	Countdown.each((index, element) => {
		new CountdownHandler(element)
	})
}

class CountdownHandler {

	private $fixedDay: JQuery<HTMLElement>
	private $day: JQuery<HTMLElement>
	private $hour: JQuery<HTMLElement>
	private $min: JQuery<HTMLElement>
	private $sec: JQuery<HTMLElement>
	private $timestamp: number
	private _rest_in_day: number // 只取天數 2 位數
	private _remaining_day: number // 百位數  例如剩餘 1214天，百位數為 12
	private _shouldBe99: boolean // 是否應該顯示為 99


	constructor(public element: HTMLElement) {
		this.$fixedDay = $(element).prev('.fixed-day')
		this.$day = $(element).find('.pc-countdown-component__day')
		this.$hour = $(element).find('.pc-countdown-component__hour')
		this.$min = $(element).find('.pc-countdown-component__min')
		this.$sec = $(element).find('.pc-countdown-component__sec')
		this.$timestamp = Number($(element).data('timestamp'))
		const estimate_day = Math.floor((this.$timestamp - Math.floor(Date.now() / 1000)) / 60 / 60 / 24)
		this.rest_in_day = estimate_day % 100
		this.remaining_day = Math.floor(estimate_day / 100)
		this.$fixedDay.text(this.remaining_day > 0 ? this.remaining_day : '')

		const timer = setInterval(() => {
			//current timestamp
			const now = Math.floor(Date.now() / 1000)
			const rest = this.$timestamp - now
			const rest_in_sec = rest % 60
			const rest_in_min = Math.floor(rest / 60) % 60
			const rest_in_hour = Math.floor(rest / 60 / 60) % 24
			// 百位數字 例如 100天00時00分00秒 倒數應該天數顯示為99
			this.shouldBe99 = [this.rest_in_day, rest_in_hour, rest_in_min, rest_in_sec].every(num => num === 0) && this.remaining_day > 0

			this.$hour.attr('style', `--value:${rest_in_hour};`)
			this.$min.attr('style', `--value:${rest_in_min};`)
			this.$sec.attr('style', `--value:${rest_in_sec};`)

			// if rest < 0, reload the page
			if (rest < 0) {
				clearInterval(timer)
				this.$day.attr('style', '--value:0;')
				this.$hour.attr('style', '--value:0;')
				this.$min.attr('style', '--value:0;')
				this.$sec.attr('style', '--value:0;')

				location.reload()
			}
		}, 1000)
	}

	get rest_in_day() {
		return this._rest_in_day
	}

	set rest_in_day(value: number) {
		this.$day.attr('style', `--value:${value};`)
		this._rest_in_day = value
	}

	get remaining_day() {
		return this._remaining_day
	}

	set remaining_day(value: number) {
		if (value > 0) {
			this.$fixedDay.text(value)
		}
		this._remaining_day = value
	}

	get shouldBe99() {
		return this._shouldBe99
	}

	set shouldBe99(value: boolean) {
		if (value) {
			this.rest_in_day = 99
			const remaining_day = this.remaining_day - 1
			this.$fixedDay.text(remaining_day > 0 ? remaining_day : '')
		}
		this._shouldBe99 = value
	}
}
