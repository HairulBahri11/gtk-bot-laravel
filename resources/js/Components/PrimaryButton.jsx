export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center justify-center gap-1.5 rounded-lg bg-[#2a78d6] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors duration-150 ease-in-out hover:bg-[#2568bd] focus:outline-none focus:ring-2 focus:ring-[#2a78d6]/40 focus:ring-offset-2 active:bg-[#1f5aa3] dark:bg-[#3987e5] dark:hover:bg-[#2f74c9] dark:focus:ring-offset-gray-900 dark:active:bg-[#2a63aa] ${
                    disabled && 'opacity-40'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
