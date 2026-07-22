export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-300 text-[#2a78d6] shadow-sm focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-900 dark:text-[#3987e5] dark:focus:ring-[#3987e5] dark:focus:ring-offset-gray-900 ' +
                className
            }
        />
    );
}
