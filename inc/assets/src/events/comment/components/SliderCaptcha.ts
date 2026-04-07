import $ from 'jquery'
import { site_url } from '../../../utils'

const extend = function () {
	const length = arguments.length
	let target = arguments[0] || {}
	if (typeof target !== 'object' && typeof target !== 'function') {
		target = {}
	}
	if (length == 1) {
		target = this
		i--
	}
	for (let i = 1; i < length; i++) {
		const source = arguments[i]
		for (const key in source) {
			// 使用for in会遍历数组所有的可枚举属性，包括原型。
			if (Object.prototype.hasOwnProperty.call(source, key)) {
				target[key] = source[key]
			}
		}
	}
	return target
}

const isFunction = function isFunction(obj) {
	return typeof obj === 'function' && typeof obj.nodeType !== 'number'
}

export const SliderCaptcha = function (element, options) {
	this.$element = element
	this.options = extend({}, SliderCaptcha.DEFAULTS, options)
	this.$element.style.position = 'relative'
	this.$element.style.width = this.options.width + 'px'
	this.$element.style.margin = '0 auto'
	this.init()
}

SliderCaptcha.VERSION = '1.0'
SliderCaptcha.Author = ''
SliderCaptcha.DEFAULTS = {
	width: 280, // canvas宽度
	height: 155, // canvas高度
	PI: Math.PI,
	sliderL: 42, // 滑块边长
	sliderR: 9, // 滑块半径
	offset: 5, // 容错偏差
	loadingText: '正在加載中...',
	failedText: '請再試一次',
	barText: '向右滑動填充拼圖',
	repeatIcon: 'fa fa-repeat',
	maxLoadCount: 3,
	localImages() {
		return `${site_url}/wp-content/plugins/power-course/inc/assets/src//assets/images/Pic${Math.round(Math.random() * 4)}.jpg`
	},
	verify(arr, url) {
		let ret = false
		$.ajax({
			url,
			data: {
				datas: JSON.stringify(arr),
			},
			dataType: 'json',
			type: 'post',
			async: false,
			success(result) {
				ret = JSON.stringify(result)
				console.log('返回结果：' + ret)
			},
		})
		return ret
	},
	remoteUrl: null,
}

// function Plugin(option) {
// 	const $this = document.getElementById(option.id)
// 	const options = typeof option === 'object' && option
// 	return new SliderCaptcha($this, options)
// }

// window.sliderCaptcha = Plugin
// window.sliderCaptcha.Constructor = SliderCaptcha

const _proto = SliderCaptcha.prototype
_proto.init = function () {
	this.initDOM()
	this.initImg()
	this.bindEvents()
}

_proto.initDOM = function () {
	const createElement = function (tagName, className) {
		const elment = document.createElement(tagName)
		elment.className = className
		return elment
	}

	const createCanvas = function (width, height) {
		const canvas = document.createElement('canvas')
		canvas.width = width
		canvas.height = height
		return canvas
	}

	const canvas = createCanvas(this.options.width - 2, this.options.height) // 画布
	const block = canvas.cloneNode(true) // 滑块
	const sliderContainer = createElement('div', 'sliderContainer')

	const refreshIconContainer = document.createElement('div')
	refreshIconContainer.innerHTML = `
  <svg class="size-6 refreshIcon" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:cc="http://creativecommons.org/ns#" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:svg="http://www.w3.org/2000/svg" xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" viewBox="0 0 30 30" version="1.1" inkscape:version="0.92.4 (f8dce91, 2019-08-02)" sodipodi:docname="repeat.svg" fill="#4b5563"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g> <defs></defs> <sodipodi:namedview pagecolor="#4b5563" bordercolor="#4b5563" borderopacity="1.0" inkscape:pageopacity="0.0" inkscape:pageshadow="2" inkscape:zoom="32" inkscape:cx="14.349319" inkscape:cy="19.133748" inkscape:document-units="px" inkscape:current-layer="layer1" showgrid="true" units="px" inkscape:window-width="1366" inkscape:window-height="713" inkscape:window-x="0" inkscape:window-y="0" inkscape:window-maximized="1" showguides="false"> <inkscape:grid type="xygrid" id="grid816"></inkscape:grid> </sodipodi:namedview> <metadata id="metadata819"> <rdf:rdf> <cc:work rdf:about=""> <dc:format>image/svg+xml</dc:format> <dc:type rdf:resource="http://purl.org/dc/dcmitype/StillImage"></dc:type> <dc:title> </dc:title> </cc:work> </rdf:rdf> </metadata> <g inkscape:label="Layer 1" inkscape:groupmode="layer" id="layer1" transform="translate(0,-289.0625)"> <path style="color:#4b5563;font-style:normal;font-variant:normal;font-weight:normal;font-stretch:normal;font-size:medium;line-height:normal;font-family:sans-serif;font-variant-ligatures:normal;font-variant-position:normal;font-variant-caps:normal;font-variant-numeric:normal;font-variant-alternates:normal;font-feature-settings:normal;text-indent:0;text-align:start;text-decoration:none;text-decoration-line:none;text-decoration-style:solid;text-decoration-color:#4b5563;letter-spacing:normal;word-spacing:normal;text-transform:none;writing-mode:lr-tb;direction:ltr;text-orientation:mixed;dominant-baseline:auto;baseline-shift:baseline;text-anchor:start;white-space:normal;shape-padding:0;clip-rule:nonzero;display:inline;overflow:visible;visibility:visible;opacity:1;isolation:auto;mix-blend-mode:normal;color-interpolation:sRGB;color-interpolation-filters:linearRGB;solid-color:#4b5563;solid-opacity:1;vector-effect:none;fill:#4b5563;fill-opacity:1;fill-rule:nonzero;stroke:none;stroke-width:2;stroke-linecap:butt;stroke-linejoin:miter;stroke-miterlimit:4;stroke-dasharray:none;stroke-dashoffset:0;stroke-opacity:1;color-rendering:auto;image-rendering:auto;shape-rendering:auto;text-rendering:auto;enable-background:accumulate" d="M 15 3 L 15 6 C 10.041282 6 6 10.04128 6 15 C 6 19.95872 10.041282 24 15 24 C 19.958718 24 24 19.95872 24 15 C 24 13.029943 23.355254 11.209156 22.275391 9.7246094 L 20.849609 11.150391 C 21.575382 12.253869 22 13.575008 22 15 C 22 18.87784 18.877838 22 15 22 C 11.122162 22 8 18.87784 8 15 C 8 11.12216 11.122162 8 15 8 L 15 11 L 20 7 L 15 3 z " transform="translate(0,289.0625)"></path> </g> </g></svg>
`
	const refreshIcon = refreshIconContainer.firstElementChild
	const sliderMask = createElement('div', 'sliderMask')
	const sliderbg = createElement('div', 'sliderbg')
	const slider = createElement('div', 'slider')
	const sliderIconContainer = document.createElement('div')
	sliderIconContainer.innerHTML = `
  <svg class="size-6 sliderIcon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <g stroke-width="0"></g>
    <g stroke-linecap="round" stroke-linejoin="round"></g>
    <g>
      <path d="M4 12H20M20 12L14 6M20 12L14 18" stroke="#4b5563" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
    </g>
  </svg>
`
	const sliderIcon = sliderIconContainer.firstElementChild
	const text = createElement('span', 'sliderText')

	block.className = 'block'
	text.innerHTML = this.options.barText

	const el = this.$element
	el.appendChild(canvas)
	el.appendChild(refreshIcon)
	el.appendChild(block)
	slider.appendChild(sliderIcon)
	sliderMask.appendChild(slider)
	sliderContainer.appendChild(sliderbg)
	sliderContainer.appendChild(sliderMask)
	sliderContainer.appendChild(text)
	el.appendChild(sliderContainer)

	const _canvas = {
		canvas,
		block,
		sliderContainer,
		refreshIcon,
		slider,
		sliderMask,
		sliderIcon,
		text,
		canvasCtx: canvas.getContext('2d'),
		blockCtx: block.getContext('2d'),
	}

	if (isFunction(Object.assign)) {
		Object.assign(this, _canvas)
	} else {
		extend(this, _canvas)
	}
}

_proto.initImg = function () {
	const that = this
	const isIE = window.navigator.userAgent.indexOf('Trident') > -1
	const L = this.options.sliderL + this.options.sliderR * 2 + 3 // 滑块实际边长
	const drawImg = function (ctx, operation) {
		const l = that.options.sliderL
		const r = that.options.sliderR
		const PI = that.options.PI
		const x = that.x
		const y = that.y
		ctx.beginPath()
		ctx.moveTo(x, y)
		ctx.arc(x + l / 2, y - r + 2, r, 0.72 * PI, 2.26 * PI)
		ctx.lineTo(x + l, y)
		ctx.arc(x + l + r - 2, y + l / 2, r, 1.21 * PI, 2.78 * PI)
		ctx.lineTo(x + l, y + l)
		ctx.lineTo(x, y + l)
		ctx.arc(x + r - 2, y + l / 2, r + 0.4, 2.76 * PI, 1.24 * PI, true)
		ctx.lineTo(x, y)
		ctx.lineWidth = 2
		ctx.fillStyle = 'rgba(255, 255, 255, 0.7)'
		ctx.strokeStyle = 'rgba(255, 255, 255, 0.7)'
		ctx.stroke()
		ctx[operation]()
		ctx.globalCompositeOperation = isIE ? 'xor' : 'destination-over'
	}

	const getRandomNumberByRange = function (start, end) {
		return Math.round(Math.random() * (end - start) + start)
	}
	const img = new Image()
	img.crossOrigin = 'Anonymous'
	let loadCount = 0
	img.onload = function () {
		// 随机创建滑块的位置
		that.x = getRandomNumberByRange(L + 10, that.options.width - (L + 10))
		that.y = getRandomNumberByRange(
			10 + that.options.sliderR * 2,
			that.options.height - (L + 10),
		)
		drawImg(that.canvasCtx, 'fill')
		drawImg(that.blockCtx, 'clip')

		that.canvasCtx.drawImage(
			img,
			0,
			0,
			that.options.width - 2,
			that.options.height,
		)
		that.blockCtx.drawImage(
			img,
			0,
			0,
			that.options.width - 2,
			that.options.height,
		)
		const y = that.y - that.options.sliderR * 2 - 1
		const ImageData = that.blockCtx.getImageData(that.x - 3, y, L, L)
		that.block.width = L
		that.blockCtx.putImageData(ImageData, 0, y + 1)
		that.text.textContent = that.text.getAttribute('data-text')
	}
	img.onerror = function () {
		loadCount++
		if (window.location.protocol === 'file:') {
			loadCount = that.options.maxLoadCount
			console.error(
				'can\'t load pic resource file from File protocal. Please try http or https',
			)
		}
		if (loadCount >= that.options.maxLoadCount) {
			that.text.textContent = '加載失敗'
			that.classList.add('text-danger')
			return
		}
		img.src = that.options.localImages()
	}
	img.setSrc = function () {
		let src = ''
		loadCount = 0
		that.text.classList.remove('text-danger')
		if (isFunction(that.options.setSrc)) src = that.options.setSrc()
		if (!src || src === '')
			src =
				'https://picsum.photos/' +
				that.options.width +
				'/' +
				that.options.height +
				'/?image=' +
				Math.round(Math.random() * 20)
		if (isIE) {
			// IE浏览器无法通过img.crossOrigin跨域，使用ajax获取图片blob然后转为dataURL显示
			const xhr = new XMLHttpRequest()
			xhr.onloadend = function (e) {
				const file = new FileReader() // FileReader仅支持IE10+
				file.readAsDataURL(e.target.response)
				file.onloadend = function (e) {
					img.src = e.target.result
				}
			}
			xhr.open('GET', src)
			xhr.responseType = 'blob'
			xhr.send()
		} else img.src = src
	}
	img.setSrc()
	this.text.setAttribute('data-text', this.options.barText)
	this.text.textContent = this.options.loadingText
	this.img = img
}

_proto.clean = function () {
	this.canvasCtx.clearRect(0, 0, this.options.width, this.options.height)
	this.blockCtx.clearRect(0, 0, this.options.width, this.options.height)
	this.block.width = this.options.width
}

_proto.bindEvents = function () {
	const that = this
	this.$element.addEventListener('selectstart', function () {
		return false
	})

	this.refreshIcon.addEventListener('click', function () {
		that.text.textContent = that.options.barText
		that.reset()
		if (isFunction(that.options.onRefresh))
			that.options.onRefresh.call(that.$element)
	})

	let originX,
		originY,
		trail = [],
		isMouseDown = false

	const handleDragStart = function (e) {
		if (that.text.classList.contains('text-danger')) return
		originX = e.clientX || e.touches[0].clientX
		originY = e.clientY || e.touches[0].clientY
		isMouseDown = true
	}

	const handleDragMove = function (e) {
		if (!isMouseDown) return false
		const eventX = e.clientX || e.touches[0].clientX
		const eventY = e.clientY || e.touches[0].clientY
		const moveX = eventX - originX
		const moveY = eventY - originY
		if (moveX < 0 || moveX + 40 > that.options.width) return false
		that.slider.style.left = moveX - 1 + 'px'
		const blockLeft =
			((that.options.width - 40 - 20) / (that.options.width - 40)) * moveX
		that.block.style.left = blockLeft + 'px'

		that.sliderContainer.classList.add('sliderContainer_active')
		that.sliderMask.style.width = moveX + 4 + 'px'
		trail.push(Math.round(moveY))
	}

	const handleDragEnd = function (e) {
		if (!isMouseDown) return false
		isMouseDown = false
		const eventX = e.clientX || e.changedTouches[0].clientX
		if (eventX === originX) return false
		that.sliderContainer.classList.remove('sliderContainer_active')
		that.trail = trail
		const data = that.verify()
		if (data.spliced && data.verified) {
			that.sliderContainer.classList.add('sliderContainer_success')
			if (isFunction(that.options.onSuccess))
				that.options.onSuccess.call(that.$element)
		} else {
			that.sliderContainer.classList.add('sliderContainer_fail')
			if (isFunction(that.options.onFail))
				that.options.onFail.call(that.$element)
			setTimeout(function () {
				that.text.innerHTML = that.options.failedText
				that.reset()
			}, 1000)
		}
	}

	this.slider.addEventListener('mousedown', handleDragStart)
	this.slider.addEventListener('touchstart', handleDragStart)
	document.addEventListener('mousemove', handleDragMove)
	document.addEventListener('touchmove', handleDragMove)
	document.addEventListener('mouseup', handleDragEnd)
	document.addEventListener('touchend', handleDragEnd)

	document.addEventListener('mousedown', function () {
		return false
	})
	document.addEventListener('touchstart', function () {
		return false
	})
	document.addEventListener('swipe', function () {
		return false
	})
}

_proto.verify = function () {
	const arr = this.trail // 拖动时y轴的移动距离
	const left = parseInt(this.block.style.left)
	let verified = false
	if (this.options.remoteUrl !== null) {
		verified = this.options.verify(arr, this.options.remoteUrl)
	} else {
		const sum = function (x, y) {
			return x + y
		}
		const square = function (x) {
			return x * x
		}
		const average = arr.reduce(sum) / arr.length
		const deviations = arr.map(function (x) {
			return x - average
		})
		const stddev = Math.sqrt(deviations.map(square).reduce(sum) / arr.length)
		verified = stddev !== 0
	}
	return {
		spliced: Math.abs(left - this.x) < this.options.offset,
		verified,
	}
}

_proto.reset = function () {
	this.sliderContainer.classList.remove('sliderContainer_fail')
	this.sliderContainer.classList.remove('sliderContainer_success')
	this.slider.style.left = 0
	this.block.style.left = 0
	this.sliderMask.style.width = 0
	this.clean()
	this.text.setAttribute('data-text', this.text.textContent)
	this.text.textContent = this.options.loadingText
	this.img.setSrc()
}
